<?php

namespace ImapPolyfill\Connection\Imap;

use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;
use ImapPolyfill\Support\Timeouts;

/**
 * The engine's stream with imap_timeout()'s read timeout applied to it.
 *
 * It has to happen here rather than after connect(): the very first read is
 * the server greeting, done inside connect(), and that is exactly the read
 * that blocks when a spec points cleartext at a TLS-only port. PHP would
 * otherwise fall back to default_socket_timeout, which imap_timeout() is
 * supposed to override.
 */
final class TimedStream extends ImapStream
{
    /** @param array<string, mixed> $options */
    public function open(string $transport, string $host, int $port, int $timeout, array $options = []): bool
    {
        $opened = parent::open($transport, $host, $port, $timeout, $options);

        // A single socket timeout covers both directions, so c-client's
        // separate write timeout has nowhere to go; the read one wins,
        // being the one that governs waiting for a server that went quiet.
        $this->setTimeout(Timeouts::seconds(IMAP_READTIMEOUT));

        return $opened;
    }
}
