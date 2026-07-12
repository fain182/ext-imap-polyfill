<?php

namespace ImapPolyfill\Connection;

/**
 * Gateway to webklex's raw IMAP protocol connection, for the handful of
 * operations (UID<->msgno translation, low-level SEARCH/FETCH with an
 * explicit UID mode, STORE) that have no equivalent on the high-level
 * Client/Folder API. Unwraps the ->validatedData() envelope every call
 * returns, so callers get plain values back instead of reaching through
 * client->getConnection()->method()->validatedData() themselves.
 */
final class Protocol
{
    /** @var string[]|null */
    private ?array $capabilities = null;

    public function __construct(private readonly \Webklex\PHPIMAP\Client $client)
    {
    }

    /**
     * webklex's ProtocolInterface only declares the operations its high-level
     * Client/Folder API needs; the lower-level wire operations this class
     * gateways to (fetch, requestAndResponse, escapeString, ->stream) only
     * exist on the concrete ImapProtocol, which is the only protocol this
     * polyfill supports (see README limitations).
     */
    private function connection(): \Webklex\PHPIMAP\Connection\Protocols\ImapProtocol
    {
        $connection = $this->client->getConnection();
        assert($connection instanceof \Webklex\PHPIMAP\Connection\Protocols\ImapProtocol);

        return $connection;
    }

    /**
     * @param string[] $tokens
     *
     * @return int[]
     */
    public function search(array $tokens, int $uidMode): array
    {
        return $this->connection()->search($tokens, $uidMode)->validatedData();
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, string>
     */
    public function headers(array $ids, string $type, int $uidMode): array
    {
        return $this->connection()->headers($ids, $type, $uidMode)->validatedData();
    }

    /**
     * @param string[] $items
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(array $items, array $ids, ?int $to, int $uidMode): array
    {
        return $this->connection()->fetch($items, $ids, $to, $uidMode)->validatedData();
    }

    /**
     * @return array<int, int>
     */
    public function getUid(): array
    {
        return $this->connection()->getUid()->validatedData();
    }

    /**
     * @throws \Webklex\PHPIMAP\Exceptions\MessageNotFoundException
     */
    public function getMessageNumber(string $uid): int|string
    {
        return $this->connection()->getMessageNumber($uid)->validatedData();
    }

    /**
     * @param string[] $args
     */
    public function store(string $command, array $args): void
    {
        $this->connection()->requestAndResponse($command, $args);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function folders(string $reference, string $pattern): array
    {
        return $this->connection()->folders($reference, $pattern)->validatedData();
    }

    /**
     * webklex's folders() only speaks LIST; this is the same wire exchange
     * and response shape for LSUB.
     *
     * @return array<string, array<string, mixed>>
     */
    public function subscribedFolders(string $reference, string $pattern): array
    {
        $connection = $this->connection();
        $response = $connection
            ->requestAndResponse('LSUB', $connection->escapeString($reference, $pattern))
            ->setCanBeEmpty(true);

        $result = [];
        foreach ($response->validatedData() as $item) {
            if (!is_array($item) || count($item) !== 4 || $item[0] !== 'LSUB') {
                continue;
            }

            $name = str_replace('\\\\', '\\', str_replace('\\"', '"', $item[3]));
            $result[$name] = ['delimiter' => $item[2], 'flags' => $item[1]];
        }

        return $result;
    }

    public function copy(string $sequence, string $folder, int $uidMode): void
    {
        $this->client->getConnection()->copyMessage($folder, $sequence, null, $uidMode)->validatedData();
    }

    public function noop(): void
    {
        $this->client->getConnection()->noop()->validatedData();
    }

    /**
     * @param string[] $items
     *
     * @return array<string, int>
     */
    public function folderStatus(string $folder, array $items): array
    {
        return $this->client->getConnection()->folderStatus($folder, $items)->validatedData();
    }

    public function hasCapability(string $capability): bool
    {
        // Cached like c-client's stream->cap: CAPABILITY goes out once per
        // connection, not once per gated command.
        $this->capabilities ??= $this->connection()->getCapabilities()->validatedData();

        return in_array($capability, $this->capabilities, true);
    }

    /**
     * webklex's getQuota() hardcodes a "#user/" quota-root prefix, so both
     * quota reads speak the RFC 2087 wire commands directly. GETQUOTAROOT
     * answers with the same untagged QUOTA responses as GETQUOTA (plus a
     * QUOTAROOT line this polyfill doesn't surface, since ext-imap's
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
        $connection = $this->connection();
        $root = $connection->escapeString($quotaRoot);
        assert(is_string($root));
        $connection->requestAndResponse('SETQUOTA', [$root, "(STORAGE {$mailboxSize})"]);
    }

    /**
     * @return array<int, array{name: string, usage: int, limit: int}>
     */
    private function quotaCommand(string $command, string $argument): array
    {
        $connection = $this->connection();
        $escaped = $connection->escapeString($argument);
        assert(is_string($escaped));
        $response = $connection->requestAndResponse($command, [$escaped])->setCanBeEmpty(true);

        $resources = [];
        foreach ($response->validatedData() as $line) {
            if (!is_array($line) || ($line[0] ?? null) !== 'QUOTA' || !is_array($line[2] ?? null)) {
                continue;
            }
            // The parenthesized quota list is (name usage limit) triples.
            $triple = array_values($line[2]);
            for ($i = 0; $i + 3 <= count($triple); $i += 3) {
                $resources[] = [
                    'name' => (string) $triple[$i],
                    'usage' => (int) $triple[$i + 1],
                    'limit' => (int) $triple[$i + 2],
                ];
            }
        }

        return $resources;
    }
}
