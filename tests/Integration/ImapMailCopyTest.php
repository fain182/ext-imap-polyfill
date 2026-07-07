<?php

namespace ImapPolyfill\Tests\Integration;

class ImapMailCopyTest extends GreenmailTestCase
{
    public function test_copies_a_message_to_another_folder(): void
    {
        $sourceName = 'CopySrcBox'.uniqid();
        $targetName = 'CopyDstBox'.uniqid();
        $seedClient = $this->makeFolder($sourceName);
        $seedClient->createFolder($targetName, expunge: false);
        $seedClient->getFolder($sourceName)->appendMessage("Subject: Travelling\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($sourceName), self::USER, self::PASSWORD);

        $result = imap_mail_copy($connection, '1', $targetName);

        $this->assertTrue($result);
        $status = imap_status($connection, self::mailboxSpec($targetName), SA_MESSAGES);
        $this->assertSame(1, $status->messages);

        // A plain copy leaves the source message untouched.
        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(0, $overview[0]->deleted);
    }

    public function test_returns_false_for_a_full_spec_target(): void
    {
        $sourceName = 'CopySrcBox'.uniqid();
        $targetName = 'CopyDstBox'.uniqid();
        $seedClient = $this->makeFolder($sourceName);
        $seedClient->createFolder($targetName, expunge: false);
        $seedClient->getFolder($sourceName)->appendMessage("Subject: Travelling\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($sourceName), self::USER, self::PASSWORD);

        // Unlike imap_append()/imap_status(), COPY takes the bare folder
        // name: c-client sends the mailbox argument verbatim, so a
        // "{host}folder" spec names a nonexistent folder server-side.
        $this->assertFalse(imap_mail_copy($connection, '1', self::mailboxSpec($targetName)));
    }

    public function test_cp_move_marks_the_source_message_deleted_without_expunging(): void
    {
        $sourceName = 'CopySrcBox'.uniqid();
        $targetName = 'CopyDstBox'.uniqid();
        $seedClient = $this->makeFolder($sourceName);
        $seedClient->createFolder($targetName, expunge: false);
        $seedClient->getFolder($sourceName)->appendMessage("Subject: Travelling\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($sourceName), self::USER, self::PASSWORD);

        $result = imap_mail_copy($connection, '1', $targetName, CP_MOVE);

        $this->assertTrue($result);
        $status = imap_status($connection, self::mailboxSpec($targetName), SA_MESSAGES);
        $this->assertSame(1, $status->messages);

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(1, $overview[0]->deleted);
        $this->assertSame(1, imap_num_msg($connection));
    }

    public function test_returns_false_for_a_nonexistent_target_folder(): void
    {
        $sourceName = 'CopySrcBox'.uniqid();
        $seedClient = $this->makeFolder($sourceName);
        $seedClient->getFolder($sourceName)->appendMessage("Subject: Travelling\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($sourceName), self::USER, self::PASSWORD);

        $this->assertFalse(imap_mail_copy($connection, '1', 'NoSuchFolder'.uniqid()));
    }

    public function test_rejects_options_outside_cp_uid_and_cp_move(): void
    {
        $sourceName = 'CopySrcBox'.uniqid();
        $seedClient = $this->makeFolder($sourceName);
        $seedClient->getFolder($sourceName)->appendMessage("Subject: Travelling\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($sourceName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        imap_mail_copy($connection, '1', $sourceName, 16);
    }
}
