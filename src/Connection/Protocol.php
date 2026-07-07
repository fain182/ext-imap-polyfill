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
    public function __construct(private readonly \Webklex\PHPIMAP\Client $client)
    {
    }

    /**
     * @param string[] $tokens
     *
     * @return int[]
     */
    public function search(array $tokens, int $uidMode): array
    {
        return $this->client->getConnection()->search($tokens, $uidMode)->validatedData();
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, string>
     */
    public function headers(array $ids, string $type, int $uidMode): array
    {
        return $this->client->getConnection()->headers($ids, $type, $uidMode)->validatedData();
    }

    /**
     * @param string[] $items
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(array $items, array $ids, ?string $criteria, int $uidMode): array
    {
        return $this->client->getConnection()->fetch($items, $ids, $criteria, $uidMode)->validatedData();
    }

    /**
     * @return array<int, int>
     */
    public function getUid(): array
    {
        return $this->client->getConnection()->getUid()->validatedData();
    }

    /**
     * @throws \Webklex\PHPIMAP\Exceptions\MessageNotFoundException
     */
    public function getMessageNumber(string $uid): int|string
    {
        return $this->client->getConnection()->getMessageNumber($uid)->validatedData();
    }

    /**
     * @param string[] $args
     */
    public function store(string $command, array $args): void
    {
        $this->client->getConnection()->requestAndResponse($command, $args);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function folders(string $reference, string $pattern): array
    {
        return $this->client->getConnection()->folders($reference, $pattern)->validatedData();
    }

    /**
     * webklex's folders() only speaks LIST; this is the same wire exchange
     * and response shape for LSUB.
     *
     * @return array<string, array<string, mixed>>
     */
    public function subscribedFolders(string $reference, string $pattern): array
    {
        $connection = $this->client->getConnection();
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
}
