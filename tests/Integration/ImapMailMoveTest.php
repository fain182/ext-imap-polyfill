<?php

namespace ImapPolyfill\Tests\Integration;

class ImapMailMoveTest extends GreenmailTestCase
{
    public function test_moves_a_message_and_marks_the_source_deleted_without_expunging(): void
    {
        $sourceName = 'MoveSrcBox'.uniqid();
        $targetName = 'MoveDstBox'.uniqid();
        $seedClient = $this->makeFolder($sourceName);
        $seedClient->createFolder($targetName, expunge: false);
        $seedClient->getFolder($sourceName)->appendMessage("Subject: Moving\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($sourceName), self::USER, self::PASSWORD);

        $result = imap_mail_move($connection, '1', $targetName);

        $this->assertTrue($result);
        $status = imap_status($connection, self::mailboxSpec($targetName), SA_MESSAGES);
        $this->assertSame(1, $status->messages);

        // c-client's "move" is copy + \Deleted: the source message stays
        // until an explicit expunge.
        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(1, $overview[0]->deleted);
        $this->assertSame(1, imap_num_msg($connection));
    }

    public function test_cp_uid_moves_by_uid_when_it_diverges_from_msgno(): void
    {
        [$sourceName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'MoveUidBox'.uniqid(),
            "Subject: Survivor\r\n\r\nKeep me"
        );
        $targetName = 'MoveDstBox'.uniqid();
        $this->makeFolder($targetName);

        $connection = imap_open(self::mailboxSpec($sourceName), self::USER, self::PASSWORD);

        $result = imap_mail_move($connection, (string) $survivorUid, $targetName, CP_UID);

        $this->assertTrue($result);
        $status = imap_status($connection, self::mailboxSpec($targetName), SA_MESSAGES);
        $this->assertSame(1, $status->messages);
    }

    public function test_rejects_options_other_than_cp_uid(): void
    {
        $sourceName = 'MoveSrcBox'.uniqid();
        $seedClient = $this->makeFolder($sourceName);
        $seedClient->getFolder($sourceName)->appendMessage("Subject: Moving\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($sourceName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        imap_mail_move($connection, '1', $sourceName, 9999);
    }
}
