<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Support\Diagnostics;
use ImapPolyfill\Tests\Support\MailServer;
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
    /**
     * The fixture's passdb accepts any username, and each one gets a mail
     * store of its own: that is what lets `make check` run the suite here
     * and in the parity container at once without either seeing the
     * other's folders.
     */
    protected static function user(): string
    {
        return getenv('IMAP_POLYFILL_DOVECOT_USER') ?: 'testuser';
    }

    protected static function password(): string
    {
        return 'testpass';
    }

    /** Dovecot's own hierarchy separator, where Greenmail uses a dot. */
    protected const SEPARATOR = '/';

    protected function setUp(): void
    {
        // A refused probe is the answer here, not something to report.
        $socket = Diagnostics::discard(static function () use (&$errno, &$errstr) {
            return fsockopen(self::host(), self::port(), $errno, $errstr, 2);
        });

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
        return MailServer::dovecot()->host;
    }

    protected static function port(): int
    {
        return MailServer::dovecot()->port;
    }

    protected static function pop3Port(): int
    {
        return (int) (getenv('IMAP_POLYFILL_DOVECOT_POP3_PORT') ?: 13111);
    }

    /** Every spec here carries /tls-sslv23, for the reason MailServer::dovecot() gives. */
    protected static function mailboxSpec(string $folder = 'INBOX'): string
    {
        return MailServer::dovecot()->spec($folder);
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
