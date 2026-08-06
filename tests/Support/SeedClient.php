<?php

namespace ImapPolyfill\Tests\Support;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;
use DirectoryTree\ImapEngine\Support\Str;

/**
 * Minimal IMAP client for seeding Greenmail fixtures, independent of the
 * polyfill code under test.
 */
final class SeedClient
{
    private readonly SeedConnection $connection;

    public function __construct(string $host, int $port, string $user, string $password)
    {
        $this->connection = new SeedConnection(new ImapStream());
        $this->connection->connect($host, $port);
        $this->connection->login($user, $password);
    }

    public function createFolder(string $name): void
    {
        $this->connection->create($name);
    }

    public function deleteFolder(string $name): void
    {
        $this->connection->delete($name);
    }

    public function getFolder(string $name): SeedFolder
    {
        return new SeedFolder($this->connection, $name);
    }

    public function openFolder(string $name, bool $readOnly = false): void
    {
        $readOnly ? $this->connection->examine($name) : $this->connection->select($name);
    }

    public function expunge(): void
    {
        $this->connection->expunge();
    }

    /**
     * @param list<string> $tokens
     */
    public function command(string $command, array $tokens = []): ResponseCollection
    {
        return $this->connection->command($command, $tokens);
    }

    /**
     * The server's own answer to a SORT over the selected folder, for tests
     * that need to compare against it rather than hardcode an ordering.
     *
     * @return int[]
     */
    public function sorted(string $program): array
    {
        foreach ($this->connection->command('SORT', ["({$program})", 'US-ASCII', 'ALL']) as $response) {
            if ((string) $response->type() === 'SORT') {
                return array_map('intval', array_map('strval', $response->tokensAfter(2)));
            }
        }

        return [];
    }

    /**
     * The flags the server holds for a message in the selected folder.
     *
     * @return string[]
     */
    public function flagsOf(int $msgno): array
    {
        foreach ($this->connection->command('FETCH', [(string) $msgno, Str::list(['FLAGS'])]) as $response) {
            $data = $response->tokenAt(3);

            if ($data instanceof ListData && $data->tokenAt(1) instanceof ListData) {
                return array_map('strval', $data->tokenAt(1)->tokens());
            }
        }

        return [];
    }

    /**
     * msgno => uid for the currently selected folder.
     *
     * @return array<int, int>
     */
    public function uids(): array
    {
        $uids = [];
        foreach ($this->connection->command('FETCH', ['1:*', Str::list(['UID'])]) as $response) {
            $data = $response->tokenAt(3);

            if (!$data instanceof ListData) {
                continue;
            }

            $values = $data->values();
            $position = array_search('UID', $values, true);

            if ($position !== false) {
                $uids[(int) (string) $response->type()] = (int) $values[$position + 1];
            }
        }

        return $uids;
    }
}
