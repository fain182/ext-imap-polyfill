<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapDeleteTest extends GreenmailTestCase
{
    public function test_marks_a_message_as_deleted_without_expunging(): void
    {
        $folderName = 'DeleteBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_delete($connection, '1');

        $this->assertTrue($result);

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(1, $overview[0]->deleted);
        $this->assertSame(1, imap_num_msg($connection));
    }
}
