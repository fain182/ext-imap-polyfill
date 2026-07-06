<?php

namespace ImapPolyfill\Tests\Integration;

class ImapAppendTest extends GreenmailTestCase
{
    public function test_appends_a_message_to_the_folder(): void
    {
        $folderName = 'AppendBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_append($connection, self::mailboxSpec($folderName), "Subject: Appended\r\n\r\nBody text\r\n");

        $this->assertTrue($result);
        $this->assertSame(1, imap_num_msg($connection));
    }

    public function test_appends_a_message_with_flags(): void
    {
        $folderName = 'AppendBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        imap_append($connection, self::mailboxSpec($folderName), "Subject: Appended\r\n\r\nBody text\r\n", '\\Seen');

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(1, $overview[0]->seen);
    }
}
