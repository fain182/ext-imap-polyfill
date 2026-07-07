<?php

namespace ImapPolyfill\Tests\Integration;

final class ImapThreadTest extends GreenmailTestCase
{
    public function test_reference_chain_nests_replies_and_keeps_unrelated_message_as_sibling(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $client = $this->makeFolder($folderName);
        $folder = $client->getFolder($folderName);
        $folder->appendMessage("Message-ID: <root@example.com>\r\nSubject: Original\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nRoot");
        $folder->appendMessage("Message-ID: <reply1@example.com>\r\nIn-Reply-To: <root@example.com>\r\nReferences: <root@example.com>\r\nSubject: Re: Original\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nReply1");
        $folder->appendMessage("Message-ID: <reply2@example.com>\r\nIn-Reply-To: <reply1@example.com>\r\nReferences: <root@example.com> <reply1@example.com>\r\nSubject: Re: Original\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\nReply2");
        $folder->appendMessage("Message-ID: <unrelated@example.com>\r\nSubject: Something else\r\nDate: Tue, 07 Jul 2026 08:00:00 +0000\r\n\r\nUnrelated");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame([
            '0.num' => 4, '0.next' => 0, '0.branch' => 1,
            '1.num' => 1, '1.next' => 2,
            '2.num' => 2, '2.next' => 3,
            '3.num' => 3, '3.next' => 0, '3.branch' => 0,
            '2.branch' => 0,
            '1.branch' => 0,
        ], imap_thread($connection));

        imap_close($connection);
    }

    public function test_matching_base_subject_groups_replies_without_explicit_references(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $client = $this->makeFolder($folderName);
        $folder = $client->getFolder($folderName);
        $folder->appendMessage("Message-ID: <a@example.com>\r\nSubject: Shared Topic\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nA");
        $folder->appendMessage("Message-ID: <b@example.com>\r\nSubject: Re: Shared Topic\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nB");
        $folder->appendMessage("Message-ID: <c@example.com>\r\nSubject: Fwd: Shared Topic\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\nC");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame([
            '0.num' => 1, '0.next' => 1,
            '1.num' => 2, '1.next' => 0, '1.branch' => 2,
            '2.num' => 3, '2.next' => 0, '2.branch' => 0,
            '0.branch' => 0,
        ], imap_thread($connection));

        imap_close($connection);
    }

    public function test_se_uid_returns_uids_instead_of_msgnos(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $client = $this->makeFolder($folderName);
        $folder = $client->getFolder($folderName);
        $folder->appendMessage("Message-ID: <x1@example.com>\r\nSubject: X\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nX1");
        $folder->appendMessage("Message-ID: <x2@example.com>\r\nIn-Reply-To: <x1@example.com>\r\nReferences: <x1@example.com>\r\nSubject: Re: X\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nX2");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $msgnoTree = imap_thread($connection);
        $uidTree = imap_thread($connection, SE_UID);

        $this->assertSame($msgnoTree, $uidTree);

        imap_close($connection);
    }

    public function test_empty_mailbox_returns_false(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertFalse(imap_thread($connection));

        imap_close($connection);
    }
}
