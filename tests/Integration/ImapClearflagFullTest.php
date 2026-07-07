<?php

namespace ImapPolyfill\Tests\Integration;

class ImapClearflagFullTest extends GreenmailTestCase
{
    public function test_clears_a_flag(): void
    {
        $folderName = 'ClearFlagBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);
        imap_setflag_full($connection, '1', '\\Flagged \\Seen');

        $result = imap_clearflag_full($connection, '1', '\\Flagged');

        $this->assertTrue($result);
        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(0, $overview[0]->flagged);
        $this->assertSame(1, $overview[0]->seen);
    }

    public function test_rejects_options_other_than_st_uid(): void
    {
        $folderName = 'ClearFlagBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        imap_clearflag_full($connection, '1', '\\Seen', 9999);
    }

    public function test_imap_undelete_clears_the_deleted_flag(): void
    {
        $folderName = 'UndeleteBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);
        imap_delete($connection, '1');

        $result = imap_undelete($connection, '1');

        $this->assertTrue($result);
        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(0, $overview[0]->deleted);
    }
}
