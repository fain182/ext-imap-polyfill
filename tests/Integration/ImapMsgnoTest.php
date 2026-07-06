<?php

namespace ImapPolyfill\Tests\Integration;

class ImapMsgnoTest extends GreenmailTestCase
{
    public function test_returns_the_message_number_for_a_known_uid(): void
    {
        $folderName = 'MsgnoBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);
        $uid = imap_uid($connection, 1);

        $this->assertSame(1, imap_msgno($connection, $uid));
    }

    public function test_returns_zero_for_an_unknown_uid(): void
    {
        $folderName = 'MsgnoBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame(0, imap_msgno($connection, 999999));
    }
}
