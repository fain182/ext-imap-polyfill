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

    protected static function mailboxSpec(string $folder = 'INBOX'): string
    {
        return sprintf('{%s:%d/imap/novalidate-cert}%s', self::host(), self::port(), $folder);
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
