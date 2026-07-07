<?php

namespace ImapPolyfill\Tests\Integration;

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

    protected static function pop3Port(): int
    {
        return (int) (getenv('IMAP_POLYFILL_TEST_POP3_PORT') ?: 13110);
    }

    protected static function mailboxSpec(string $folder = 'INBOX'): string
    {
        return sprintf('{%s:%d/imap/novalidate-cert}%s', self::host(), self::port(), $folder);
    }

    protected static function pop3MailboxSpec(): string
    {
        return sprintf('{%s:%d/pop3/novalidate-cert}INBOX', self::host(), self::pop3Port());
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
        $seedClient->getConnection()->requestAndResponse('STORE', ['1', '+FLAGS.SILENT', '(\\Deleted)']);
        $seedClient->expunge();
        $folder->appendMessage($survivorMessage);
        $seedClient->openFolder($folderName, true);

        $uids = $seedClient->getConnection()->getUid()->validatedData();

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
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);
        $seedClient->deleteFolder($folderName, expunge: false);

        return $connection;
    }
}
