<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\Support\SeedClient;

use PHPUnit\Framework\TestCase;

abstract class GreenmailTestCase extends TestCase
{
    protected static function user(): string
    {
        return getenv('IMAP_POLYFILL_TEST_USER') ?: 'testuser';
    }

    protected static function password(): string
    {
        return getenv('IMAP_POLYFILL_TEST_PASSWORD') ?: 'testpass';
    }

    /**
     * The connection flags every spec in this suite carries. Overridable so
     * the same tests can run against a server reached over TLS with a real
     * certificate, where "/novalidate-cert" would be wrong.
     */
    protected static function flags(): string
    {
        return getenv('IMAP_POLYFILL_TEST_FLAGS') ?: '/imap/novalidate-cert';
    }

    /** What the seeding client has to speak to reach the same server. */
    protected static function encryption(): string
    {
        return str_contains(self::flags(), '/ssl') ? 'ssl' : '';
    }

    protected static function host(): string
    {
        return getenv('IMAP_POLYFILL_TEST_HOST') ?: '127.0.0.1';
    }

    protected static function port(): int
    {
        return (int) (getenv('IMAP_POLYFILL_TEST_PORT') ?: 13143);
    }

    protected static function pop3Port(): int
    {
        return (int) (getenv('IMAP_POLYFILL_TEST_POP3_PORT') ?: 13110);
    }

    protected static function imapsPort(): int
    {
        return (int) (getenv('IMAP_POLYFILL_TEST_IMAPS_PORT') ?: 13993);
    }

    protected static function pop3sPort(): int
    {
        return (int) (getenv('IMAP_POLYFILL_TEST_POP3S_PORT') ?: 13995);
    }

    protected static function mailboxSpec(string $folder = 'INBOX'): string
    {
        return sprintf('{%s:%d%s}%s', self::host(), self::port(), self::flags(), $folder);
    }

    protected static function pop3MailboxSpec(): string
    {
        return sprintf('{%s:%d/pop3/novalidate-cert}INBOX', self::host(), self::pop3Port());
    }

    /**
     * Creates a fresh, empty folder directly on the server and returns a
     * connected client, for seeding test fixtures without depending on the
     * polyfill functions under test.
     */
    protected function makeFolder(string $name): SeedClient
    {
        $client = new SeedClient(self::host(), self::port(), self::user(), self::password(), self::encryption());
        $client->createFolder($name);

        return $client;
    }

    /**
     * Seeds a folder where the one remaining message has msgno=1 but uid=2:
     * append a throwaway message, delete + expunge it, then append the real
     * one. Needed to prove FT_UID/ST_UID/SE_UID code paths actually key off
     * the UID rather than silently working by coincidence when uid==msgno.
     *
     * @return array{0: string, 1: int} [folder name, uid of the surviving message]
     */
    protected function makeMsgnoUidMismatchFixture(string $folderName, string $survivorMessage): array
    {
        $seedClient = $this->makeFolder($folderName);
        $folder = $seedClient->getFolder($folderName);
        $folder->appendMessage("Subject: Throwaway\r\n\r\nDiscard me");
        $seedClient->openFolder($folderName);
        $seedClient->command('STORE', ['1', '+FLAGS.SILENT', '(\\Deleted)']);
        $seedClient->expunge();
        $folder->appendMessage($survivorMessage);
        $seedClient->openFolder($folderName, true);

        $uids = $seedClient->uids();

        return [$folderName, (int) $uids[1]];
    }

    /**
     * Opens a connection to a fresh folder, then deletes that folder out from
     * under it via a second client — a realistic way to make a *subsequent*
     * operation on an otherwise still-open connection genuinely fail server
     * side, for exercising the catch/ErrorStack path of the imap_* wrappers.
     */
    protected function openConnectionToFolderThatThenDisappears(string $folderName): \IMAP\Connection
    {
        $seedClient = $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());
        $seedClient->deleteFolder($folderName);

        return $connection;
    }
}
