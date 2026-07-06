<?php

namespace ImapPolyfill\Tests\Integration;

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

    public function test_ft_uid_deletes_by_uid_when_it_diverges_from_msgno(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'DeleteUidBox' . uniqid(),
            "Subject: Survivor\r\n\r\nKeep me"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        imap_delete($connection, (string) $survivorUid, FT_UID);

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(1, $overview[0]->deleted);
    }

    public function test_deletes_a_range_of_messages(): void
    {
        $folderName = 'DeleteBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $folder = $seedClient->getFolder($folderName);
        $folder->appendMessage("Subject: One\r\n\r\nBody 1");
        $folder->appendMessage("Subject: Two\r\n\r\nBody 2");
        $folder->appendMessage("Subject: Three\r\n\r\nBody 3");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        imap_delete($connection, '1:2');

        $overview = imap_fetch_overview($connection, '1:3');
        $this->assertSame(1, $overview[0]->deleted);
        $this->assertSame(1, $overview[1]->deleted);
        $this->assertSame(0, $overview[2]->deleted);
    }
}
