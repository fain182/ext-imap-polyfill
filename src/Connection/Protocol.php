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
    /** @var string[]|null */
    private ?array $capabilities = null;

    public function __construct(private readonly ImapEngineConnection $connection)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function selectOrExamine(string $folder, bool $readOnly): array
    {
        $responses = $this->connection->sendAndCollect(
            $readOnly ? 'EXAMINE' : 'SELECT',
            [Str::literal($folder)],
        );

        $result = [];
        foreach ($responses as $response) {
            $type = (string) $response->type();

            if ($type === 'FLAGS') {
                $flags = $response->tokenAt(2);
                $result['flags'][] = $flags instanceof Data ? self::value($flags) : [];
                continue;
            }

            // "* 3 EXISTS" / "* 1 RECENT": the count is the response type slot.
            $keyword = (string) ($response->tokenAt(2) ?? '');
            if ($keyword === 'EXISTS' || $keyword === 'RECENT') {
                $result[strtolower($keyword)] = (int) $type;
            }
        }

        return $result;
    }

    /**
     * @param string[] $tokens
     *
     * @return int[]
     */
    public function search(array $tokens, int $uidMode): array
    {
        $command = $uidMode === UidMode::UID ? 'UID SEARCH' : 'SEARCH';

        foreach ($this->connection->sendAndCollect($command, $tokens) as $response) {
            if ((string) $response->type() !== 'SEARCH') {
                continue;
            }

            return array_map('intval', array_map('strval', $response->tokensAfter(2)));
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
     * per-mailbox uid table.
     *
     * @return array<int, int>
     */
    public function getUid(): array
    {
        /** @var array<int, int> $uids */
        $uids = $this->fetchSet('1:*', ['UID'], UidMode::MSGNO);

        return $uids;
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

    public function hasCapability(string $capability): bool
    {
        // Cached like c-client's stream->cap: CAPABILITY goes out once per
        // connection, not once per gated command.
        $this->capabilities ??= array_map('strval', $this->connection->capability()->tokensAfter(2));

        return in_array($capability, $this->capabilities, true);
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
