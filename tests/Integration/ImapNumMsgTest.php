<?php

namespace ImapPolyfill\Tests\Integration;

class ImapNumMsgTest extends GreenmailTestCase
{
    public function test_returns_zero_for_an_empty_mailbox(): void
    {
        $folderName = 'EmptyBox' . uniqid();
        $this->makeFolder($folderName);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame(0, imap_num_msg($connection));
    }

    public function test_returns_the_number_of_messages_in_the_mailbox(): void
    {
        $folderName = 'NumMsgBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody text");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame(1, imap_num_msg($connection));
    }
}
