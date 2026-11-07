<?php

namespace ImapPolyfill\Connection\Imap;

use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;
use ImapPolyfill\Support\Diagnostics;
use ImapPolyfill\Support\Timeouts;

/**
 * The engine's stream with imap_timeout()'s read timeout applied to it,
 * and with PHP's own warnings about the socket kept to itself.
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
        // The engine dials with "@", which Diagnostics says is not enough.
        $opened = Diagnostics::discard(fn () => parent::open($transport, $host, $port, $timeout, $options));

        // A single socket timeout covers both directions, so c-client's
        // two have to become one; see Timeouts::socketSeconds() for which.
        $this->setTimeout(Timeouts::socketSeconds());

        return $opened;
    }

    /**
     * The STARTTLS handshake, whose failure the caller reports as its own
     * exception; OpenSSL's warning about it is not one the extension raises.
     */
    public function setSocketSetCrypto(bool $enabled, ?int $method): bool|int
    {
        return Diagnostics::discard(fn () => parent::setSocketSetCrypto($enabled, $method));
    }
}
