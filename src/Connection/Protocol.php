<?php

namespace ImapPolyfill\Connection;

use DirectoryTree\ImapEngine\Connection\Responses\Data\Data;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Tokens\Nil;
use DirectoryTree\ImapEngine\Connection\Tokens\Number;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Support\Str;
use ImapPolyfill\Connection\Imap\ImapEngineConnection;

/**
 * Gateway to the raw IMAP wire, turning ImapEngine's token trees into the
 * plain arrays the imap_* layer consumes. Every command the polyfill needs
 * goes through here, including the ones ImapEngine has no method for (LSUB,
 * msgno-space SEARCH/FETCH/STORE/COPY, SETQUOTA).
 */
final class Protocol
{
    /** @var array<int, int>|null msgno => uid for the folder $uidTableFor describes */
    private ?array $uidTable = null;

    private string $uidTableFor = '';

    private ?string $selectedFolder = null;

    private bool $selectedReadOnly = false;

    /** @var list<string> the flags the last real SELECT/EXAMINE advertised */
    private array $selectedFlags = [];

    public function __construct(private readonly ImapEngineConnection $connection)
    {
    }

    public function selectOrExamine(string $folder, bool $readOnly): FolderState
    {
        // c-client selects a mailbox once and keeps it: the counts it reports
        // afterwards come from untagged responses arriving on whatever
        // command runs next, not from re-selecting. Re-selecting per call
        // costs a round trip on every single imap_* function, and against a
        // mailbox of any size that round trip is the dominant cost.
        if ($folder === $this->selectedFolder && $readOnly === $this->selectedReadOnly) {
            $counts = $this->connection->counts();

            if ($counts['exists'] !== null) {
                return new FolderState(
                    $counts['exists'],
                    $counts['recent'] ?? 0,
                    flags: $this->selectedFlags,
                );
            }
        }

        $this->connection->forgetCounts();

        $responses = $this->connection->sendAndCollect(
            $readOnly ? 'EXAMINE' : 'SELECT',
            [Str::literal($folder)],
        );

        $exists = null;
        $recent = 0;
        $uidValidity = null;
        $flags = [];

        foreach ($responses as $response) {
            $type = (string) $response->type();

            if ($type === 'FLAGS') {
                $advertised = $response->tokenAt(2);
                foreach ($advertised instanceof Data ? $advertised->tokens() : [] as $flag) {
                    $flags[] = (string) $flag;
                }
                continue;
            }

            // "* 3 EXISTS" / "* 1 RECENT": the count is the response type slot.
            $keyword = (string) ($response->tokenAt(2) ?? '');
            if ($keyword === 'EXISTS') {
                $exists = (int) $type;
                continue;
            }
            if ($keyword === 'RECENT') {
                $recent = (int) $type;
                continue;
            }

            // "* OK [UIDVALIDITY 1234]", kept only to invalidate the uid table.
            $code = $response->tokenAt(2);
            if ($code instanceof ResponseCodeData && (string) ($code->tokenAt(0) ?? '') === 'UIDVALIDITY') {
                $uidValidity = (int) (string) ($code->tokenAt(1) ?? 0);
            }
        }

        // An untagged EXISTS is required of a SELECT/EXAMINE response by both
        // IMAP4rev1 and IMAP4rev2, so its absence is a parse that went wrong
        // rather than a server being terse — and a folder that answers zero
        // messages reads as a working polyfill on an empty one.
        if ($exists === null) {
            throw new \RuntimeException('no EXISTS in '.($readOnly ? 'EXAMINE' : 'SELECT').' response');
        }

        $this->selectedFolder = $folder;
        $this->selectedReadOnly = $readOnly;
        $this->selectedFlags = $flags;

        // A real SELECT is the one moment the uid table can be trusted to
        // describe these messages; from here the count tracked on the
        // connection decides when it stops.
        $fingerprint = sprintf('%s/%d/%d', $folder, $uidValidity ?? 0, $exists);
        if ($fingerprint !== $this->uidTableFor) {
            $this->uidTable = null;
            $this->uidTableFor = $fingerprint;
        }

        return new FolderState($exists, $recent, $uidValidity, $flags);
    }

    /**
     * @param string[] $tokens
     *
     * @return int[]
     */
    public function search(array $tokens, int $uidMode, string $charset = ''): array
    {
        $command = $uidMode === UidMode::UID ? 'UID SEARCH' : 'SEARCH';

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
     * @param string[] $searchTokens
     *
     * @return int[]|null
     */
    public function sort(string $program, string $charset, array $searchTokens, int $uidMode): ?array
    {
        try {
            $responses = $this->connection->sendAndCollect(
                $uidMode === UidMode::UID ? 'UID SORT' : 'SORT',
                ["({$program})", self::astring($charset), ...$searchTokens],
            );
        } catch (CommandFailedException $e) {
            if ($e->status() !== 'BAD') {
                throw $e;
            }

            return null;
        }

        foreach ($responses as $response) {
            if ((string) $response->type() !== 'SORT') {
                continue;
            }

            return array_map('intval', array_map('strval', $response->tokensAfter(2)));
        }

        return [];
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
     * @param string[] $searchTokens
     *
     * @return array<int, mixed>|null
     */
    public function thread(string $algorithm, string $charset, array $searchTokens, int $uidMode): ?array
    {
        try {
            $responses = $this->connection->sendAndCollect(
                $uidMode === UidMode::UID ? 'UID THREAD' : 'THREAD',
                [$algorithm, self::astring($charset), ...$searchTokens],
            );
        } catch (CommandFailedException $e) {
            if ($e->status() !== 'BAD') {
                throw $e;
            }

            return null;
        }

        foreach ($responses as $response) {
            if ((string) $response->type() !== 'THREAD') {
                continue;
            }

            return array_map(self::value(...), $response->tokensAfter(2));
        }

        return [];
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, string>
     */
    public function headers(array $ids, string $type, int $uidMode): array
    {
        return $this->fetch(["{$type}.HEADER"], $ids, null, $uidMode);
    }

    /**
     * @param string[] $items
     * @param int[] $ids
     *
     * @return array<int, mixed>
     */
    public function fetch(array $items, array $ids, ?int $to, int $uidMode): array
    {
        $set = $to === null
            ? implode(',', $ids)
            : ((int) reset($ids)).':'.$to;

        return $this->fetchSet($set, $items, $uidMode);
    }

    /**
     * msgno => uid for the whole selected folder, the way c-client keeps its
     * per-mailbox uid table — cached like one too, since imap_uid() is
     * routinely called once per message and this fetch covers the whole
     * mailbox. selectOrExamine() drops the cache as soon as the folder,
     * its UIDVALIDITY or its message count changes.
     *
     * @return array<int, int>
     */
    public function getUid(): array
    {
        $exists = $this->connection->counts()['exists'];
        if ($this->uidTable !== null && $exists !== null && count($this->uidTable) !== $exists) {
            $this->uidTable = null;
        }

        if ($this->uidTable !== null) {
            return $this->uidTable;
        }

        /** @var array<int, int> $uids */
        $uids = $this->fetchSet('1:*', ['UID'], UidMode::MSGNO);

        return $this->uidTable = $uids;
    }

    /**
     * @throws MessageNotFoundException
     */
    public function getMessageNumber(string $uid): int
    {
        foreach ($this->getUid() as $msgno => $candidate) {
            if ((string) $candidate === $uid) {
                return $msgno;
            }
        }

        throw new MessageNotFoundException('message number not found: '.$uid);
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

    public function copy(string $sequence, string $folder, int $uidMode): void
    {
        $this->connection->sendAndCollect(
            $uidMode === UidMode::UID ? 'UID COPY' : 'COPY',
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

    /**
     * @return array<int, mixed>
     */
    public function bodyStructure(int $id, bool $byUid): array
    {
        $data = $this->fetch(['BODYSTRUCTURE'], [$id], null, $byUid ? UidMode::UID : UidMode::MSGNO);
        $structure = $data[$id] ?? reset($data);

        if (!is_array($structure)) {
            throw new \RuntimeException('no BODYSTRUCTURE in FETCH response');
        }

        return $structure;
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
        $this->connection->sendAndCollect('SETQUOTA', [
            Str::literal($quotaRoot),
            "(STORAGE {$mailboxSize})",
        ]);
    }

    /**
     * A single requested item collapses to its scalar value per id instead of
     * a one-key array, the shape the imap_* layer has always consumed.
     *
     * @param string[] $items
     *
     * @return array<int, mixed>
     */
    private function fetchSet(string $set, array $items, int $uidMode): array
    {
        $responses = $this->connection->sendAndCollect(
            $uidMode === UidMode::UID ? 'UID FETCH' : 'FETCH',
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
            $key = $uidMode === UidMode::UID ? (int) ($pairs['UID'] ?? 0) : (int) (string) $response->type();

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
    private static function astring(string $value): string
    {
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
