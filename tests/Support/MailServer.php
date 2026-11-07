<?php

namespace ImapPolyfill\Tests\Support;

/**
 * One of the two fixtures, as a connection spec is built for it.
 *
 * The integration suites and the fixture generators read both servers from
 * here, so a recorded answer and the test replaying it reach the server the
 * same way.
 */
final class MailServer
{
    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $flags,
    ) {
    }

    /**
     * The flags are overridable so the same tests can run against a server
     * reached over TLS with a real certificate, where "/novalidate-cert"
     * would be wrong.
     */
    public static function greenmail(): self
    {
        return new self(
            getenv('IMAP_POLYFILL_TEST_HOST') ?: '127.0.0.1',
            (int) (getenv('IMAP_POLYFILL_TEST_PORT') ?: 13143),
            getenv('IMAP_POLYFILL_TEST_FLAGS') ?: '/imap/novalidate-cert',
        );
    }

    /**
     * /tls-sslv23 is not decoration.
     *
     * This fixture advertises STARTTLS, so both implementations upgrade on
     * the way in. c-client's plain /tls builds its context with
     * TLSv1_client_method() — TLS 1.0 and nothing else (ssl_unix.c) — which
     * no current server completes, and /tls-sslv23 is the switch that makes
     * it negotiate a version instead. Without it every Dovecot spec would
     * work here and fail under `make parity`. It is Dovecot's alone: on
     * Greenmail, which announces nothing, the switch makes the upgrade
     * compulsory and the connection fails.
     *
     * /novalidate-cert for the fixture's self-signed certificate.
     */
    public static function dovecot(): self
    {
        return new self(
            getenv('IMAP_POLYFILL_DOVECOT_HOST') ?: '127.0.0.1',
            (int) (getenv('IMAP_POLYFILL_DOVECOT_PORT') ?: 13144),
            '/imap/tls-sslv23/novalidate-cert',
        );
    }

    /**
     * $extraFlags carries the ones a single test is about (/readonly), which
     * belong after the connection flags every spec shares.
     */
    public function spec(string $folder = '', string $extraFlags = ''): string
    {
        return sprintf('{%s:%d%s%s}%s', $this->host, $this->port, $this->flags, $extraFlags, $folder);
    }
}
