<?php

namespace ImapPolyfill\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Checks the polyfill's own internal IMAP\Connection::$readOnly flag.
 * Deliberately a host-only unit test, not part of tests/Integration: real
 * ext-imap's IMAP\Connection is opaque (no public properties at all), so
 * this assertion would be meaningless run against the genuine extension —
 * see ImapOpenTest for the behavioral (parity-safe) equivalent.
 */
class ImapOpenReadOnlyTest extends TestCase
{
    public function test_op_readonly_sets_the_read_only_flag(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped('IMAP\Connection is opaque on real ext-imap; no readOnly property to check.');
        }

        $backend = new \ImapPolyfill\Connection\Imap\ImapBackend(
            new \ImapPolyfill\Connection\Imap\ImapEngineConnection(new \DirectoryTree\ImapEngine\Connection\Streams\ImapStream()),
            'example.com',
        );
        $connection = new \IMAP\Connection($backend, 'INBOX', '{example.com:143/imap', 'user', readOnly: true);

        $this->assertTrue($connection->isReadOnly());
    }

    public function test_defaults_to_not_read_only(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped('IMAP\Connection is opaque on real ext-imap; no readOnly property to check.');
        }

        $backend = new \ImapPolyfill\Connection\Imap\ImapBackend(
            new \ImapPolyfill\Connection\Imap\ImapEngineConnection(new \DirectoryTree\ImapEngine\Connection\Streams\ImapStream()),
            'example.com',
        );
        $connection = new \IMAP\Connection($backend, 'INBOX', '{example.com:143/imap', 'user');

        $this->assertFalse($connection->isReadOnly());
    }
}
