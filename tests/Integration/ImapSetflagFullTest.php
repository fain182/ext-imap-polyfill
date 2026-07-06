<?php

namespace ImapPolyfill\Tests\Integration;

class ImapSetflagFullTest extends GreenmailTestCase
{
    public function test_sets_flags_on_a_message(): void
    {
        $folderName = 'SetFlagBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_setflag_full($connection, '1', '\\Seen \\Flagged');

        $this->assertTrue($result);

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(1, $overview[0]->seen);
        $this->assertSame(1, $overview[0]->flagged);
    }

    public function test_sets_flags_on_a_range_of_messages(): void
    {
        $folderName = 'SetFlagBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $folder = $seedClient->getFolder($folderName);
        $folder->appendMessage("Subject: One\r\n\r\nBody 1");
        $folder->appendMessage("Subject: Two\r\n\r\nBody 2");
        $folder->appendMessage("Subject: Three\r\n\r\nBody 3");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        imap_setflag_full($connection, '1:2', '\\Seen');

        $overview = imap_fetch_overview($connection, '1:3');
        $this->assertSame(1, $overview[0]->seen);
        $this->assertSame(1, $overview[1]->seen);
        $this->assertSame(0, $overview[2]->seen);
    }

    public function test_sets_flags_on_a_comma_separated_list(): void
    {
        $folderName = 'SetFlagBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $folder = $seedClient->getFolder($folderName);
        $folder->appendMessage("Subject: One\r\n\r\nBody 1");
        $folder->appendMessage("Subject: Two\r\n\r\nBody 2");
        $folder->appendMessage("Subject: Three\r\n\r\nBody 3");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        imap_setflag_full($connection, '1,3', '\\Flagged');

        $overview = imap_fetch_overview($connection, '1:3');
        $this->assertSame(1, $overview[0]->flagged);
        $this->assertSame(0, $overview[1]->flagged);
        $this->assertSame(1, $overview[2]->flagged);
    }

    public function test_st_uid_targets_by_uid_when_it_diverges_from_msgno(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'SetFlagUidBox' . uniqid(),
            "Subject: Survivor\r\n\r\nKeep me"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        imap_setflag_full($connection, (string) $survivorUid, '\\Flagged', ST_UID);

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(1, $overview[0]->flagged);
    }
}
