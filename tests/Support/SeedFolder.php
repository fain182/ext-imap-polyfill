<?php

namespace ImapPolyfill\Tests\Support;

/**
 * A folder on a SeedClient's connection, used to append fixture messages.
 */
final class SeedFolder
{
    public function __construct(
        private readonly SeedConnection $connection,
        private readonly string $path,
    ) {
    }

    /**
     * @param string[]|null $flags
     */
    public function appendMessage(string $message, ?array $flags = null): void
    {
        $this->connection->append($this->path, $message, $flags);
    }
}
