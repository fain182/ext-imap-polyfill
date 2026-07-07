<?php

namespace ImapPolyfill\Tests\Integration;

class ImapStatusTest extends GreenmailTestCase
{
    public function test_sa_all_reports_all_counters(): void
    {
        $folderName = 'StatusBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $folder = $seedClient->getFolder($folderName);
        $folder->appendMessage("Subject: One\r\n\r\nBody 1");
        $folder->appendMessage("Subject: Two\r\n\r\nBody 2");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);
        imap_setflag_full($connection, '1', '\\Seen');

        $status = imap_status($connection, self::mailboxSpec($folderName), SA_ALL);

        $this->assertIsObject($status);
        $this->assertSame(2, $status->messages);
        $this->assertSame(1, $status->unseen);
        $this->assertIsInt($status->recent);
        $this->assertIsInt($status->uidnext);
        $this->assertIsInt($status->uidvalidity);
    }

    public function test_reports_only_the_requested_items(): void
    {
        $folderName = 'StatusBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: One\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $status = imap_status($connection, self::mailboxSpec($folderName), SA_MESSAGES);

        $this->assertSame(1, $status->messages);
        $this->assertSame(SA_MESSAGES, $status->flags);
        $this->assertFalse(property_exists($status, 'unseen'));
        $this->assertFalse(property_exists($status, 'uidnext'));
    }

    public function test_returns_false_for_a_nonexistent_folder(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);

        $this->assertFalse(imap_status($connection, self::mailboxSpec('NoSuchFolder'.uniqid()), SA_ALL));
    }

    public function test_rejects_flags_outside_the_sa_constants(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        imap_status($connection, self::mailboxSpec(), 4096);
    }
}
