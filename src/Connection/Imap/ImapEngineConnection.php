<?php

namespace ImapPolyfill\Connection\Imap;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ImapConnection;

/**
 * ImapEngine's connection with its send/collect cycle exposed.
 *
 * ImapEngine keeps each command's responses behind a protected $result, so
 * the commands it has no method for — LSUB, msgno-space SEARCH/FETCH/STORE/
 * COPY, SETQUOTA — cannot be issued through its public API at all. This is
 * the only extension point the polyfill needs; the response shapes the
 * imap_* layer expects are built in Connection\Protocol.
 */
final class ImapEngineConnection extends ImapConnection
{
    /**
     * @param list<string|array{0: string, 1: string}> $tokens
     *
     * @return ResponseCollection<array-key, \DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse>
     */
    public function sendAndCollect(string $command, array $tokens = []): ResponseCollection
    {
        $this->send($command, $tokens, $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged();
    }
}
