<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapUidTest extends GreenmailTestCase
{
    public function test_returns_the_uid_for_a_message_number(): void
    {
        $folderName = 'UidBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertIsInt(imap_uid($connection, 1));
        $this->assertGreaterThan(0, imap_uid($connection, 1));
    }

    public function test_returns_false_for_an_out_of_range_message_number(): void
    {
        $folderName = 'UidBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertFalse(@imap_uid($connection, 999));
    }
}
