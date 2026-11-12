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
use ImapPolyfill\Message\SearchKey;
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
                LoginTrials::attempt(static function () use ($connection, $credentials): void {
                    $connection->login($credentials->user, $credentials->password);
                });
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
    public function search(SearchProgram $program, UidMode $uidMode, ?string $charset = null): array
    {
        // imap_search() answers a program that asks for nothing but ALL from
        // the stream, without a command and without the charset, which is
        // never looked at: its "silly ALL or seq-only search" branch.
        if (self::onlyAll($program)) {
            $count = $this->connection->messages()->count();

            if ($uidMode === UidMode::Msgno && $count !== null) {
                return $count === 0 ? [] : range(1, $count);
            }

            $table = $this->uidTable();

            return $uidMode === UidMode::Uid ? $table->uids() : array_keys($table->byMsgno());
        }

        $tokens = self::searchTokens($program);
        $command = $uidMode === UidMode::Uid ? 'UID SEARCH' : 'SEARCH';

        // CHARSET comes before the criteria, as in c-client's imap_search():
        // without it the server has no way to read a term whose bytes fall
        // outside ASCII, and answers BAD rather than searching.
        if ($charset !== null && $charset !== '') {
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
        if ($to === null) {
            return $this->fetchSet(implode(',', $ids), $items, $uidMode, static fn (int $id): bool => in_array($id, $ids, true));
        }

        $from = (int) reset($ids);

        return $this->fetchSet($from.':'.$to, $items, $uidMode, static fn (int $id): bool => $id >= $from && $id <= $to);
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
            $uids = $this->fetchSet('1:*', ['UID'], UidMode::Msgno, static fn (int $msgno): bool => true);

            return new UidTable($uids);
        }

        $unknown = $messages->unknownUids();

        // An empty folder has no uids, and asking it for the ones it has is
        // itself an error on some servers ("1:*" over nothing).
        if ($unknown !== []) {
            $first = $unknown[0];
            $last = end($unknown);
            $asked = static fn (int $msgno): bool => $msgno >= $first && $msgno <= $last;

            foreach ($this->fetchSet($first.':'.$last, ['UID'], UidMode::Msgno, $asked) as $msgno => $uid) {
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
     * @return list<ListedFolder>
     */
    public function folders(string $reference, string $pattern): array
    {
        return $this->folderList('LIST', $reference, $pattern);
    }

    /**
     * @return list<ListedFolder>
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

        // No response for the message is the server saying it is not
        // there, which the caller answers for; fetchSet() has already
        // refused one that came back without the item asked for.
        if (!isset($data[$messageNum])) {
            throw new MessageNotFoundException('message not found: '.$messageNum);
        }

        $structure = $data[$messageNum];

        if (!is_array($structure)) {
            throw new \RuntimeException('BODYSTRUCTURE in FETCH response is not a list');
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
     * c-client never reads a FETCH response as the answer to the command in
     * flight: imap_parse_unsolicited() files every one under the message it
     * names, item by item, and the caller then reads its own message's
     * cache. So a FETCH for a message nobody asked about — a flag change
     * another session made, volunteered mid-command as RFC 3501 §7 allows —
     * answers nothing here either, and two responses for one message add up
     * rather than the second replacing the first. A message that was asked
     * for and came back without an item it was asked for is the server
     * breaking its contract, not a value to make up: the wrappers report it
     * as they report any other failed command.
     *
     * @param string[] $items
     * @param \Closure(int): bool $asked whether the set sent names this id
     *
     * @return array<int, mixed>
     */
    private function fetchSet(string $set, array $items, UidMode $uidMode, \Closure $asked): array
    {
        $responses = $this->connection->sendAndCollect(
            $uidMode === UidMode::Uid ? 'UID FETCH' : 'FETCH',
            [$set, Str::list($items)],
        );

        $byId = [];
        foreach ($responses as $response) {
            if (strtoupper((string) ($response->tokenAt(2) ?? '')) !== 'FETCH') {
                continue;
            }

            $data = $response->tokenAt(3);
            if (!$data instanceof ListData) {
                continue;
            }

            $pairs = self::pairs($data);

            // An unsolicited FETCH carries no UID even during a UID FETCH,
            // which leaves it naming no message this command is about.
            $id = $uidMode === UidMode::Uid
                ? (isset($pairs['UID']) ? (int) $pairs['UID'] : null)
                : (int) (string) $response->type();

            if ($id === null || !$asked($id)) {
                continue;
            }

            $byId[$id] = array_merge($byId[$id] ?? [], $pairs);
        }

        // BODY.PEEK[...] is a request-only spelling; the server answers with
        // the plain BODY[...] name.
        $wanted = array_map(static fn (string $item): string => str_replace('.PEEK[', '[', strtoupper($item)), $items);

        $result = [];
        foreach ($byId as $id => $pairs) {
            foreach ($wanted as $item) {
                if (!array_key_exists($item, $pairs)) {
                    throw new \RuntimeException("FETCH response for {$id} lacks {$item}");
                }
            }

            $result[$id] = count($wanted) === 1 ? $pairs[$wanted[0]] : $pairs;
        }

        return $result;
    }

    /**
     * imap_parse_unsolicited() passes a LIST or LSUB response on only when
     * it opens with its attribute list and goes on to a name; one that does
     * not is not a folder it reports, so it is not one here either.
     *
     * @return list<ListedFolder>
     */
    private function folderList(string $command, string $reference, string $pattern): array
    {
        $responses = $this->connection->sendAndCollect($command, Str::literal([$reference, $pattern]));

        $result = [];
        foreach ($responses as $response) {
            if ((string) $response->type() !== $command) {
                continue;
            }

            $attributes = $response->tokenAt(2);
            $delimiter = $response->tokenAt(3);
            $name = $response->tokenAt(4);

            if (!$attributes instanceof ListData || $delimiter === null || !$name instanceof Token || $name instanceof Nil) {
                continue;
            }

            $result[] = new ListedFolder(
                (string) self::value($name),
                $delimiter instanceof Nil ? null : (string) self::value($delimiter),
                array_map('strval', $attributes->tokens()),
            );
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
    private static function onlyAll(SearchProgram $program): bool
    {
        foreach ($program->criteria as $criterion) {
            if ($criterion->key !== SearchKey::All) {
                return false;
            }
        }

        return true;
    }

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
            // imap_parse_unsolicited() ucase()s the name before it compares
            // it: an atom is an atom whatever case the server spells it in.
            $name = strtoupper((string) $tokens[$i++]);

            // Item names carry their section in brackets (BODY[TEXT]), which
            // the tokenizer splits off into a group of its own.
            $section = $tokens[$i] ?? null;
            if ($section instanceof ResponseCodeData) {
                $name .= '['.implode(' ', array_map('strval', $section->tokens())).']';
                $i++;
            }

            // An item the server sent no value for reads as NIL. c-client
            // raises nothing here either: imap_parse_unsolicited() hands each
            // item's parser whatever text follows, empty included.
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
