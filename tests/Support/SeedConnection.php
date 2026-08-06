<?php

namespace ImapPolyfill\Tests\Support;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ImapConnection;

/**
 * ImapEngine's connection with the raw send/collect cycle exposed, so
 * fixtures can issue commands its public API doesn't cover (msgno-space
 * STORE and FETCH). Deliberately duplicated from the polyfill's own
 * subclass: fixtures must not be built out of the code under test.
 */
final class SeedConnection extends ImapConnection
{
    public function command(string $command, array $tokens = []): ResponseCollection
    {
        $this->send($command, $tokens, $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged();
    }
}
