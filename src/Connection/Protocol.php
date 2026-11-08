<?php

namespace ImapPolyfill\Connection;

use DirectoryTree\ImapEngine\Connection\Responses\Data\Data;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Tokens\Nil;
use DirectoryTree\ImapEngine\Connection\Tokens\Number;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException;
use DirectoryTree\ImapEngine\Support\Str;
use ImapPolyfill\Connection\Imap\ImapEngineConnection;
use ImapPolyfill\Connection\Imap\TimedStream;
use ImapPolyfill\Mailbox\MailboxSpec;
use ImapPolyfill\Message\BodyPart;
use ImapPolyfill\Message\SearchProgram;
use ImapPolyfill\Support\CommandArgument;
use ImapPolyfill\Support\Timeouts;

/**
 * The ConnectionBackend an \IMAP\Connection speaks IMAP through, and the
 * gateway to the raw wire it speaks over: it builds every command the
 * polyfill needs — including the ones ImapEngine has no method for (LSUB,
 * msgno-space SEARCH/FETCH/STORE/COPY, SETQUOTA) — and turns ImapEngine's
 * token trees into the plain arrays the imap_* layer consumes.
 *
 * The two are one class because for IMAP there is nothing in between: a
 * separate backend forwarding each of these methods under a second name
 * would only make readers learn which of the two names does something. The
 * POP3 side splits them (Pop3Backend over Pop3Protocol) because there the
 * backend has a job of its own — synthesizing the flags, the uids and the
 * searching that POP3 has no wire equivalent for.
 */
final class Protocol implements ConnectionBackend
{
    private ?string $selectedFolder = null;

    private bool $selectedReadOnly = false;

    /** @var list<string> the flags the last real SELECT/EXAMINE advertised */
    private array $selectedFlags = [];

    public function __construct(
        private readonly ImapEngineConnection $connection,
        private readonly string $host,
    ) {
    }

    /**
     * Connects and logs in. ImapEngine's connection failures are translated
     * here, where its exceptions stop.
     */
    public static function dial(MailboxSpec $spec, Credentials $credentials): self
    {
        $connection = new ImapEngineConnection(new TimedStream());

        try {
            $connection->connect($spec->host, $spec->port, [
                // The upgrade is driven below, not here: handed
                // "starttls", ImapEngine issues STARTTLS blind, without
                // asking whether the server offers it and without the
                // refusal c-client gives when it doesn't.
                'encryption' => $spec->encryption() === 'ssl' ? 'ssl' : '',
                'validate_cert' => !$spec->switches->novalidate,
                'timeout' => Timeouts::seconds(IMAP_OPENTIMEOUT),
            ]);

            if ($spec->encryption() !== 'ssl') {
                $connection->upgradeToTls($spec->switches->tls, $spec->switches->notls);
            }

            if ($credentials->anonymous) {
                // c-client offers the client's own host name as the
                // anonymous contact address (net_localhost).
                $connection->loginAnonymous(gethostname() ?: 'localhost');
            } else {
                $credentials->assertPlaintextLoginAllowed($connection->capabilities());
                $connection->login($credentials->user, $credentials->password);
            }
        } catch (ImapConnectionFailedException $e) {
            // c-client reports "Can't connect to host,port: reason", and
            // naming the attempted port is the only way the default-port
            // choice stays observable (and parity-testable) from outside.
            $reason = $e->getPrevious()?->getMessage() ?? $e->getMessage();

            throw new \RuntimeException("Can't connect to {$spec->host},{$spec->port}: {$reason}", 0, $e);
        }

        // c-client clears its capability cache across authentication:
        // a server routinely advertises more to a logged-in client
        // than it did to a stranger, and the gated commands
        // (SORT, THREAD, QUOTA) read the second answer.
        $connection->forgetCapabilities();

        return new self($connection, $spec->host);
    }

    public function driverName(): string
    {
        return 'imap';
    }

    public function hasOverview(): bool
    {
        return true;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function upgradedToTls(): bool
    {
        return $this->connection->upgradedToTls();
    }

    /**
     * Selects the folder again whatever is already selected, which is what
     * imap_reopen() does even when it names the folder already open:
     * c-client sends the second SELECT (seen on the wire), and the server
     * clears \Recent for the session as RFC 3501 says a SELECT does.
     */
    public function reselectFolder(string $folder, bool $readOnly): FolderState
    {
        $this->selectedFolder = null;

        return $this->selectOrExamineFolder($folder, $readOnly);
    }

    public function unselect(): void
    {
        $this->selectedFolder = null;
        $this->connection->messages()->reset();

        // c-client sends it and never reads the answer (imap4r1.c,
        // imap_open), so a server refusing it, because nothing was
        // selected, leaves the error stack alone. A socket that is gone
        // still surfaces, as ConnectionClosedException.
        try {
            $this->connection->sendAndCollect('UNSELECT');
        } catch (CommandFailedException) {
        }
    }

    public function selectOrExamineFolder(string $folder, bool $readOnly): FolderState
    {
        // c-client selects a mailbox once and keeps it: the counts it reports
        // afterwards come from untagged responses arriving on whatever
        // command runs next, not from re-selecting. Re-selecting per call
        // costs a round trip on every single imap_* function, and against a
        // mailbox of any size that round trip is the dominant cost.
        if ($folder === $this->selectedFolder && $readOnly === $this->selectedReadOnly) {
            $messages = $this->connection->messages();

            if (($count = $messages->count()) !== null) {
                return new FolderState($count, $messages->recentCount(), flags: $this->selectedFlags);
            }
        }

        $messages = $this->connection->messages();
        $messages->reset();

        // The counts arrive as untagged EXISTS and RECENT, which the message
        // cache has already absorbed by the time the command completes.
        $responses = $this->connection->sendAndCollect(
            $readOnly ? 'EXAMINE' : 'SELECT',
            [Str::literal($folder)],
        );

        $flags = [];

        foreach ($responses as $response) {
            if ((string) $response->type() === 'FLAGS') {
                $advertised = $response->tokenAt(2);
                foreach ($advertised instanceof Data ? $advertised->tokens() : [] as $flag) {
                    $flags[] = (string) $flag;
                }
            }
        }

        // An untagged EXISTS is required of a SELECT/EXAMINE response by both
        // IMAP4rev1 and IMAP4rev2, so its absence is a parse that went wrong
        // rather than a server being terse — and a folder that answers zero
        // messages reads as a working polyfill on an empty one.
        $count = $messages->count()
            ?? throw new \RuntimeException('no EXISTS in '.($readOnly ? 'EXAMINE' : 'SELECT').' response');

        $this->selectedFolder = $folder;
        $this->selectedReadOnly = $readOnly;
        $this->selectedFlags = $flags;

        return new FolderState($count, $messages->recentCount(), $flags);
    }

    /**
     * @return int[]
     */
    public function search(SearchProgram $program, UidMode $uidMode, string $charset = ''): array
    {
        $tokens = self::searchTokens($program);
        $command = $uidMode === UidMode::Uid ? 'UID SEARCH' : 'SEARCH';

        // CHARSET comes before the criteria, as in c-client's imap_search():
        // without it the server has no way to read a term whose bytes fall
        // outside ASCII, and answers BAD rather than searching.
        if ($charset !== '') {
            $tokens = ['CHARSET', self::astring($charset), ...$tokens];
        }

        foreach ($this->connection->sendAndCollect($command, $tokens) as $response) {
            if ((string) $response->type() !== 'SEARCH') {
                continue;
            }

            return array_map('intval', array_map('strval', $response->tokensAfter(2)));
        }

        return [];
    }

    /**
     * Server-side sort, the way c-client's imap_sort() issues it: "UID SORT"
     * or "SORT", the caller's charset or US-ASCII, and the search program the
     * results are drawn from ("ALL" when imap_sort() got no criteria).
     *
     * Returns null when the server rejects the command outright (BAD), which
     * is c-client's cue to sort locally instead.
     *
     * @return int[]|null
     */
    public function sort(string $program, string $charset, ?SearchProgram $search, UidMode $uidMode): ?array
    {
        $tokens = $this->delegated(
            $uidMode === UidMode::Uid ? 'UID SORT' : 'SORT',
            'SORT',
            ["({$program})", self::astring($charset), ...self::searchTokens($search)],
        );

        return $tokens === null
            ? null
            : array_map('intval', array_map('strval', $tokens));
    }

    /**
     * Server-side threading, issued the way c-client's imap_thread_work()
     * does: "UID THREAD" or "THREAD", the algorithm name as a bare atom, the
     * caller's charset or US-ASCII, and the search program.
     *
     * Returns the nested id groups of the untagged THREAD response as plain
     * arrays — "(1)(2 3 (4)(5))" comes back as [[1], [2, 3, [4], [5]]] —
     * leaving the tree shaping to Message\ThreadBuilder. Null when the
     * server rejects the command, c-client's cue to thread locally.
     *
     * @return array<int, mixed>|null
     */
    public function thread(string $algorithm, string $charset, ?SearchProgram $search, UidMode $uidMode): ?array
    {
        $tokens = $this->delegated(
            $uidMode === UidMode::Uid ? 'UID THREAD' : 'THREAD',
            'THREAD',
            [$algorithm, self::astring($charset), ...self::searchTokens($search)],
        );

        return $tokens === null
            ? null
            : array_map(self::value(...), $tokens);
    }

    /**
     * The two commands c-client hands the whole job to the server for, and
     * the one refusal it takes for an answer: a BAD is the server saying it
     * cannot do this, which is imap_sort()/imap_thread()'s cue to do it
     * locally instead (imap4r1.c). Every other failure is a failure.
     *
     * Null for that BAD; otherwise the untagged response's tokens, which the
     * caller reads as the ids or the tree it asked for.
     *
     * @param list<string|array{0: string, 1: string}> $arguments
     *
     * @return array<int, Token|Data>|null
     */
    private function delegated(string $command, string $responseType, array $arguments): ?array
    {
        try {
            $responses = $this->connection->sendAndCollect($command, $arguments);
        } catch (CommandFailedException $e) {
            if ($e->status() !== 'BAD') {
                throw $e;
            }

            return null;
        }

        foreach ($responses as $response) {
            if ((string) $response->type() === $responseType) {
                return $response->tokensAfter(2);
            }
        }

        return [];
    }

    /**
     * @param string[] $items
     * @param int[] $ids
     *
     * @return array<int, mixed>
     */
    public function fetch(array $items, array $ids, ?int $to, UidMode $uidMode): array
    {
        $set = $to === null
            ? implode(',', $ids)
            : ((int) reset($ids)).':'.$to;

        return $this->fetchSet($set, $items, $uidMode);
    }

    /**
     * Read off the message cache the way c-client's mail_uid() reads it off
     * the stream: only the messages whose uid is not yet known are asked
     * for, which after the first call is none, or the few an EXISTS has
     * since added.
     */
    public function uidTable(): UidTable
    {
        $messages = $this->connection->messages();

        // Nothing selected, so nothing cached to read or fill.
        if ($messages->count() === null) {
            /** @var array<int, int> $uids */
            $uids = $this->fetchSet('1:*', ['UID'], UidMode::Msgno);

            return new UidTable($uids);
        }

        $unknown = $messages->unknownUids();

        // An empty folder has no uids, and asking it for the ones it has is
        // itself an error on some servers ("1:*" over nothing).
        if ($unknown !== []) {
            foreach ($this->fetchSet($unknown[0].':'.end($unknown), ['UID'], UidMode::Msgno) as $msgno => $uid) {
                $messages->learnUid($msgno, (int) $uid);
            }

            if ($messages->unknownUids() !== []) {
                throw new \RuntimeException('FETCH UID left message '.$messages->unknownUids()[0].' without a uid');
            }
        }

        return $messages->uidTable();
    }

    /**
     * @param string[] $args pre-formatted wire arguments (sequence set, item, flag list)
     */
    public function store(string $command, array $args): void
    {
        $this->connection->sendAndCollect($command, $args);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function folders(string $reference, string $pattern): array
    {
        return $this->folderList('LIST', $reference, $pattern);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function subscribedFolders(string $reference, string $pattern): array
    {
        return $this->folderList('LSUB', $reference, $pattern);
    }

    public function copy(string $sequence, string $folder, UidMode $uidMode): void
    {
        $this->connection->sendAndCollect(
            $uidMode === UidMode::Uid ? 'UID COPY' : 'COPY',
            [$sequence, Str::literal($folder)],
        );
    }

    public function noop(): void
    {
        $this->connection->noop();
    }

    /**
     * c-client's imap_check() sends CHECK, and the untagged EXISTS/RECENT it
     * comes back with are what imap_check() reports. It is the one place that
     * has to reach the server: everything else reads the tracked counts.
     */
    public function check(): void
    {
        $this->connection->sendAndCollect('CHECK');
    }

    public function expunge(): void
    {
        $this->connection->expunge();
    }

    public function disconnect(): void
    {
        $this->connection->disconnect();
    }

    public function createFolder(string $name): void
    {
        $this->connection->create($name);
    }

    public function deleteFolder(string $name): void
    {
        $this->connection->delete($name);
    }

    public function renameFolder(string $from, string $to): void
    {
        $this->connection->rename($from, $to);
    }

    public function subscribeFolder(string $name): void
    {
        $this->connection->subscribe($name);
    }

    public function unsubscribeFolder(string $name): void
    {
        $this->connection->unsubscribe($name);
    }

    /**
     * @param string[]|null $flags
     */
    public function appendMessage(string $folder, string $message, ?array $flags, ?string $internalDate): void
    {
        $tokens = [Str::literal($folder)];

        if ($flags) {
            $tokens[] = Str::list($flags);
        }

        if ($internalDate !== null) {
            $tokens[] = Str::literal($internalDate);
        }

        $tokens[] = Str::literal($message);

        $this->connection->sendAndCollect('APPEND', $tokens);
    }

    public function fetchBodyStructure(int $messageNum, bool $byUid): BodyPart
    {
        $data = $this->fetch(['BODYSTRUCTURE'], [$messageNum], null, $byUid ? UidMode::Uid : UidMode::Msgno);

        // An empty response is the server saying the message is not there,
        // which the caller answers for; a response that came back without
        // the item asked for is the server breaking its own contract.
        if ($data === []) {
            throw new MessageNotFoundException('message not found: '.$messageNum);
        }

        $structure = $data[$messageNum] ?? reset($data);

        if (!is_array($structure)) {
            throw new \RuntimeException('no BODYSTRUCTURE in FETCH response');
        }

        return BodyPart::fromBodyStructure($structure);
    }

    /**
     * @param string[] $items
     *
     * @return array<string, int>
     */
    public function folderStatus(string $folder, array $items): array
    {
        $response = $this->connection->status($folder, $items);
        $data = $response->tokenAt(3);

        $status = [];
        foreach ($data instanceof ListData ? self::pairs($data) : [] as $name => $value) {
            $status[strtolower($name)] = (int) $value;
        }

        return $status;
    }

    /**
     * RFC 4314 GETACL. The mailbox goes out as an astring, verbatim: like
     * COPY and unlike APPEND/STATUS, c-client hands it to the wire without
     * parsing a "{host}" prefix off it.
     *
     * @return array<string, string> identifier => rights
     */
    public function getAcl(string $mailbox): array
    {
        $this->ensureAclCapability();

        $responses = $this->connection->sendAndCollect('GETACL', [self::astring($mailbox)]);

        $acl = [];
        foreach ($responses as $response) {
            if ((string) $response->type() !== 'ACL') {
                continue;
            }

            // "* ACL <mailbox> <identifier> <rights> [<identifier> <rights>...]"
            $tokens = $response->tokensAfter(3);
            for ($i = 0; $i + 2 <= count($tokens); $i += 2) {
                $acl[(string) self::value($tokens[$i])] = (string) self::value($tokens[$i + 1]);
            }
        }

        return $acl;
    }

    public function setAcl(string $mailbox, string $id, string $rights): void
    {
        $this->ensureAclCapability();

        $this->connection->sendAndCollect('SETACL', [
            self::astring($mailbox),
            self::astring($id),
            self::astring($rights),
        ]);
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->connection->capabilities(), true);
    }

    /**
     * GETQUOTAROOT answers with the same untagged QUOTA responses as GETQUOTA
     * (plus a QUOTAROOT line this polyfill doesn't surface, since ext-imap's
     * callback only fires on QUOTA).
     *
     * @return array<int, array{name: string, usage: int, limit: int}>
     */
    public function getQuota(string $quotaRoot): array
    {
        return $this->quotaCommand('GETQUOTA', $quotaRoot);
    }

    /**
     * @return array<int, array{name: string, usage: int, limit: int}>
     */
    public function getQuotaRoot(string $mailbox): array
    {
        return $this->quotaCommand('GETQUOTAROOT', $mailbox);
    }

    public function setQuota(string $quotaRoot, int $mailboxSize): void
    {
        $this->ensureQuotaCapability();

        $this->connection->sendAndCollect('SETQUOTA', [
            Str::literal($quotaRoot),
            "(STORAGE {$mailboxSize})",
        ]);
    }

    /**
     * c-client's LEVELACL gate (imap_acl_work), the ACL twin of the quota
     * one below, down to the message it logs.
     */
    private function ensureAclCapability(): void
    {
        if (!$this->hasCapability('ACL')) {
            throw new \RuntimeException('ACL not available on this IMAP server');
        }
    }

    /**
     * c-client's LEVELQUOTA gate: without the capability no command is sent
     * and this exact message lands on the error stack.
     */
    private function ensureQuotaCapability(): void
    {
        if (!$this->hasCapability('QUOTA')) {
            throw new \RuntimeException('Quota not available on this IMAP server');
        }
    }

    /**
     * A single requested item collapses to its scalar value per id instead of
     * a one-key array, the shape the imap_* layer has always consumed.
     *
     * @param string[] $items
     *
     * @return array<int, mixed>
     */
    private function fetchSet(string $set, array $items, UidMode $uidMode): array
    {
        $responses = $this->connection->sendAndCollect(
            $uidMode === UidMode::Uid ? 'UID FETCH' : 'FETCH',
            [$set, Str::list($items)],
        );

        // BODY.PEEK[...] is a request-only spelling; the server answers with
        // the plain BODY[...] name.
        $wanted = count($items) === 1 ? str_replace('.PEEK[', '[', $items[0]) : null;

        $result = [];
        foreach ($responses as $response) {
            if ((string) ($response->tokenAt(2) ?? '') !== 'FETCH') {
                continue;
            }

            $data = $response->tokenAt(3);
            if (!$data instanceof ListData) {
                continue;
            }

            $pairs = self::pairs($data);
            $key = $uidMode === UidMode::Uid ? (int) ($pairs['UID'] ?? 0) : (int) (string) $response->type();

            if ($key === 0) {
                continue;
            }

            $result[$key] = $wanted === null ? $pairs : self::soleItem($pairs, $wanted);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $pairs
     */
    private static function soleItem(array $pairs, string $wanted): mixed
    {
        if (array_key_exists($wanted, $pairs)) {
            return $pairs[$wanted];
        }

        // A UID FETCH always carries a UID back whether or not it was asked
        // for, so it can never be the item the caller wanted.
        unset($pairs['UID']);

        return $pairs === [] ? null : reset($pairs);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function folderList(string $command, string $reference, string $pattern): array
    {
        $responses = $this->connection->sendAndCollect($command, Str::literal([$reference, $pattern]));

        $result = [];
        foreach ($responses as $response) {
            if ((string) $response->type() !== $command) {
                continue;
            }

            $flags = $response->tokenAt(2);
            $name = (string) self::value($response->tokenAt(4));

            $result[$name] = [
                'delimiter' => (string) self::value($response->tokenAt(3)),
                'flags' => $flags instanceof Data ? self::value($flags) : [],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{name: string, usage: int, limit: int}>
     */
    private function quotaCommand(string $command, string $argument): array
    {
        $this->ensureQuotaCapability();

        $responses = $this->connection->sendAndCollect($command, [Str::literal($argument)]);

        $resources = [];
        foreach ($responses as $response) {
            if ((string) $response->type() !== 'QUOTA') {
                continue;
            }

            $list = $response->tokenAt(3);
            if (!$list instanceof ListData) {
                continue;
            }

            // The parenthesized quota list is (name usage limit) triples.
            $triple = array_map('strval', $list->tokens());
            for ($i = 0; $i + 3 <= count($triple); $i += 3) {
                $resources[] = [
                    'name' => $triple[$i],
                    'usage' => (int) $triple[$i + 1],
                    'limit' => (int) $triple[$i + 2],
                ];
            }
        }

        return $resources;
    }

    /**
     * An astring the way c-client's imap_send_astring() writes one: bare when
     * every character is an ATOM-CHAR, quoted otherwise. Sending "US-ASCII"
     * quoted where c-client sends it bare is enough for a strict server (e.g.
     * GreenMail) to reject the whole command.
     */
    /**
     * The search program as SEARCH, SORT and THREAD carry it, written from
     * the parsed criteria the way imap_send_spgm() writes c-client's: each
     * keyword in capitals, each value as an IMAP string, each date as
     * imap_send_sdate() prints it. No program, or one that names nothing, is
     * "ALL". The keywords keep the caller's order rather than c-client's
     * fixed one; a SEARCH is an AND, and the order changes nothing it finds.
     *
     * @return list<string|array{0: string, 1: string}>
     */
    private static function searchTokens(?SearchProgram $program): array
    {
        $tokens = [];

        foreach ($program === null ? [] : $program->criteria as $criterion) {
            $tokens[] = $criterion->key->value;

            if ($criterion->argument !== null) {
                $tokens[] = $criterion->key->takesDate() ? $criterion->argument : self::searchString($criterion->argument);
            }
        }

        return $tokens === [] ? ['ALL'] : $tokens;
    }

    /**
     * imap_send_astring(): an atom where nothing in the value needs more, a
     * quoted string for the empty value, a control character or an atom
     * special, and a literal for anything a quoted string cannot carry —
     * 8-bit bytes, NUL, CR, LF, and the quote and backslash IMAP2 had no
     * escape for.
     *
     * @return string|array{0: string, 1: string}
     */
    private static function searchString(string $value): string|array
    {
        if (strpbrk($value, "\0\r\n\"\\") !== false || preg_match('/[\x80-\xFF]/', $value) === 1) {
            return ['{'.strlen($value).'}', $value];
        }

        if ($value === '' || preg_match('/[\x00-\x20\x7F(){*%]/', $value) === 1) {
            return '"'.$value.'"';
        }

        return $value;
    }

    private static function astring(string $value): string
    {
        // Str::literal() answers a two-part literal, an array, for a value
        // holding a line break — which is the one this refuses anyway, and
        // refusing it here is what keeps that shape out of a return typed
        // string.
        CommandArgument::assertOneCommand($value);

        return preg_match('/^[^\x00-\x20\x7F(){%*"\\\\\]]+$/', $value) === 1 ? $value : Str::literal($value);
    }

    /**
     * Walks a FETCH/STATUS data list as item-name/value pairs.
     *
     * @return array<string, mixed>
     */
    private static function pairs(ListData $data): array
    {
        $tokens = $data->tokens();
        $count = count($tokens);

        $pairs = [];
        for ($i = 0; $i < $count;) {
            $name = (string) $tokens[$i++];

            // Item names carry their section in brackets (BODY[TEXT]), which
            // the tokenizer splits off into a group of its own.
            $section = $tokens[$i] ?? null;
            if ($section instanceof ResponseCodeData) {
                $name .= '['.implode(' ', array_map('strval', $section->tokens())).']';
                $i++;
            }

            $pairs[$name] = self::value($tokens[$i++] ?? null);
        }

        return $pairs;
    }

    /**
     * Flattens a token tree the way ext-imap's callers expect to see it:
     * NIL as null, numbers as ints, lists as arrays.
     */
    private static function value(Token|Data|null $token): mixed
    {
        return match (true) {
            $token instanceof Data => array_map(self::value(...), $token->tokens()),
            $token instanceof Nil => null,
            $token instanceof Number => (int) $token->value,
            $token instanceof Token => $token->value,
            default => null,
        };
    }
}
