<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\Support\SeedClient;
use PHPUnit\Framework\TestCase;

/**
 * Base for the handful of tests that need a server Greenmail cannot be: one
 * that speaks THREAD and ACL. Deliberately standalone rather than sharing a
 * base with GreenmailTestCase — the two fixtures differ in hierarchy
 * separator, POP3 service and preexisting folders, and pretending otherwise
 * would cost more than it saves. Everything else in tests/Integration stays
 * on Greenmail.
 *
 * Tests skip themselves when the fixture isn't up (`make dovecot-up`), so a
 * bare `vendor/bin/phpunit` still runs against Greenmail alone.
 */
abstract class DovecotTestCase extends TestCase
{
    protected static function user(): string
    {
        return 'testuser';
    }

    protected static function password(): string
    {
        return 'testpass';
    }

    /** Dovecot's own hierarchy separator, where Greenmail uses a dot. */
    protected const SEPARATOR = '/';

    protected function setUp(): void
    {
        $socket = @fsockopen(self::host(), self::port(), $errno, $errstr, 2);

        if ($socket === false) {
            $this->markTestSkipped(sprintf(
                'Dovecot fixture not reachable at %s:%d (make dovecot-up).',
                self::host(),
                self::port(),
            ));
        }

        fclose($socket);
    }

    protected static function host(): string
    {
        return getenv('IMAP_POLYFILL_DOVECOT_HOST') ?: '127.0.0.1';
    }

    protected static function port(): int
    {
        return (int) (getenv('IMAP_POLYFILL_DOVECOT_PORT') ?: 13144);
    }

    protected static function pop3Port(): int
    {
        return (int) (getenv('IMAP_POLYFILL_DOVECOT_POP3_PORT') ?: 13111);
    }

    /**
     * Every spec here carries /tls-sslv23, and it is not decoration.
     *
     * This fixture advertises STARTTLS, so both implementations upgrade on
     * the way in. c-client's plain /tls builds its context with
     * TLSv1_client_method() — TLS 1.0 and nothing else (ssl_unix.c) — which
     * no current server completes, and /tls-sslv23 is the switch that makes
     * it negotiate a version instead. Without it every test in this
     * directory would pass here and fail under `make parity`, for a reason
     * that has nothing to do with what it is testing.
     *
     * /novalidate-cert for the fixture's self-signed certificate.
     */
    protected static function mailboxSpec(string $folder = 'INBOX'): string
    {
        return sprintf('{%s:%d/imap/tls-sslv23/novalidate-cert}%s', self::host(), self::port(), $folder);
    }

    /**
     * A fresh, uniquely named folder, seeded through a connection of its own
     * so the code under test isn't also the code building the fixture.
     */
    protected function makeFolder(string $name): SeedClient
    {
        $client = new SeedClient(self::host(), self::port(), self::user(), self::password());
        $client->createFolder($name);

        return $client;
    }
}
