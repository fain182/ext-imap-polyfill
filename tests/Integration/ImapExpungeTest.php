<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapExpungeTest extends GreenmailTestCase
{
    public function test_removes_messages_marked_as_deleted(): void
    {
        $folderName = 'ExpungeBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);
        imap_delete($connection, '1');

        $result = imap_expunge($connection);

        $this->assertTrue($result);
        $this->assertSame(0, imap_num_msg($connection));
    }
}
