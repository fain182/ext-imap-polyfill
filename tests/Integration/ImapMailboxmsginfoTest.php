<?php

namespace ImapPolyfill\Tests\Integration;

class ImapMailboxmsginfoTest extends GreenmailTestCase
{
    public function test_reports_counts_and_size_of_the_selected_mailbox(): void
    {
        $folderName = 'MsgInfoBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $folder = $seedClient->getFolder($folderName);
        $folder->appendMessage("Subject: One\r\n\r\nBody 1");
        $folder->appendMessage("Subject: Two\r\n\r\nBody 2");
        $folder->appendMessage("Subject: Three\r\n\r\nBody 3");
        // Consume the \Recent flags with the seeding session: c-client counts
        // recent messages as unread regardless of \Seen, so a leftover
        // \Recent would make the Unread count session-timing-dependent.
        $seedClient->openFolder($folderName);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);
        imap_setflag_full($connection, '1', '\\Seen');
        imap_delete($connection, '2');

        $info = imap_mailboxmsginfo($connection);

        $this->assertIsObject($info);
        $this->assertSame(3, $info->Nmsgs);
        $this->assertSame(2, $info->Unread);
        $this->assertSame(1, $info->Deleted);
        $this->assertIsInt($info->Size);
        $this->assertGreaterThan(0, $info->Size);
        $this->assertSame('imap', $info->Driver);
        $this->assertIsString($info->Date);
        $this->assertIsInt($info->Recent);
    }

    public function test_reports_zeroes_for_an_empty_mailbox(): void
    {
        $folderName = 'MsgInfoBox'.uniqid();
        $this->makeFolder($folderName);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $info = imap_mailboxmsginfo($connection);

        $this->assertSame(0, $info->Nmsgs);
        $this->assertSame(0, $info->Unread);
        $this->assertSame(0, $info->Deleted);
        $this->assertSame(0, $info->Size);
    }
}
