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
        // The engine dials with "@", which an error handler that does not
        // honour it — most of the ones a framework installs — sees anyway:
        // a warning the real extension never raises, about a failure the
        // exception below already reports.
        set_error_handler(static fn (): bool => true);

        try {
            $opened = parent::open($transport, $host, $port, $timeout, $options);
        } finally {
            restore_error_handler();
        }

        // A single socket timeout covers both directions, so c-client's
        // two have to become one; see Timeouts::socketSeconds() for which.
        $this->setTimeout(Timeouts::socketSeconds());

        return $opened;
    }
}
