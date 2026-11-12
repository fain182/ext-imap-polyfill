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

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());
        imap_setflag_full($connection, '1', '\\Seen');

        $status = imap_status($connection, self::mailboxSpec($folderName), SA_ALL);

        $this->assertIsObject($status);
        $this->assertSame(
            ['flags', 'messages', 'recent', 'unseen', 'uidnext', 'uidvalidity'],
            array_keys(get_object_vars($status)),
        );
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

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $status = imap_status($connection, self::mailboxSpec($folderName), SA_MESSAGES);

        $this->assertSame(1, $status->messages);
        $this->assertSame(SA_MESSAGES, $status->flags);
        $this->assertFalse(property_exists($status, 'unseen'));
        $this->assertFalse(property_exists($status, 'uidnext'));
    }

    public function test_returns_false_for_a_nonexistent_folder(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::user(), self::password());

        $this->assertFalse(imap_status($connection, self::mailboxSpec('NoSuchFolder'.uniqid()), SA_ALL));
    }

    public function test_rejects_flags_outside_the_sa_constants(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::user(), self::password());

        $this->expectException(\ValueError::class);
        imap_status($connection, self::mailboxSpec(), 4096);
    }

    public function test_refuses_a_prefix_the_imap_driver_does_not_serve(): void
    {
        $folderName = 'StatusRefused'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec(), self::user(), self::password());
        imap_errors();
        $mailbox = '{'.self::host().':0}'.$folderName;

        $this->assertFalse(imap_status($connection, $mailbox, SA_MESSAGES));
        $this->assertSame(["Can't get status of mailbox {$mailbox}: invalid remote specification"], imap_errors());
    }

    /**
     * imap_status() in imap4r1.c tests the tagged reply with imap_OK() and
     * logs nothing of its own, and imap_OK() hands a NO to mm_notify(),
     * which php_imap.c only reads for [ALERT]: the refusal is a bare false.
     */
    public function test_a_folder_the_server_refuses_records_no_error(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::user(), self::password());
        imap_errors();

        $this->assertFalse(imap_status($connection, self::mailboxSpec('NoSuchFolder'.uniqid()), SA_ALL));
        $this->assertFalse(imap_errors());
    }
}
