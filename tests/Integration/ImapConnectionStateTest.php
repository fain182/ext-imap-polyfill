<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapConnectionStateTest extends GreenmailTestCase
{
    public function test_imap_num_recent_returns_zero_for_a_fresh_mailbox(): void
    {
        $folderName = 'NumRecentBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame(0, imap_num_recent($connection));
    }

    public function test_imap_is_open_returns_true_for_an_open_connection(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);

        $this->assertTrue(imap_is_open($connection));
    }

    public function test_imap_is_open_returns_false_after_close(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);
        imap_close($connection);

        $this->assertFalse(imap_is_open($connection));
    }

    public function test_imap_reopen_switches_to_a_different_folder(): void
    {
        $folderName = 'ReopenBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec('INBOX'), self::USER, self::PASSWORD);

        $result = imap_reopen($connection, self::mailboxSpec($folderName));

        $this->assertTrue($result);
        $this->assertSame(1, imap_num_msg($connection));
    }
}
