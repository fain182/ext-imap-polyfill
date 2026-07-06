<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\TestCase;

abstract class GreenmailTestCase extends TestCase
{
    protected const USER = 'testuser';
    protected const PASSWORD = 'testpass';

    protected static function host(): string
    {
        return getenv('IMAP_POLYFILL_TEST_HOST') ?: '127.0.0.1';
    }

    protected static function port(): int
    {
        return (int) (getenv('IMAP_POLYFILL_TEST_PORT') ?: 13143);
    }

    protected static function mailboxSpec(string $folder = 'INBOX'): string
    {
        return sprintf('{%s:%d/imap/novalidate-cert}%s', self::host(), self::port(), $folder);
    }

    /**
     * Creates a fresh, empty folder directly through webklex and returns a
     * connected client, for seeding test fixtures without depending on the
     * polyfill functions under test.
     */
    protected function makeFolder(string $name): \Webklex\PHPIMAP\Client
    {
        $client = (new \Webklex\PHPIMAP\ClientManager())->make([
            'host' => self::host(),
            'port' => self::port(),
            'encryption' => false,
            'validate_cert' => false,
            'username' => self::USER,
            'password' => self::PASSWORD,
            'protocol' => 'imap',
        ]);
        $client->connect();
        $client->createFolder($name, expunge: false);

        return $client;
    }
}
