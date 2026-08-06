<?php

namespace ImapPolyfill\Tests\Integration;

final class ImapSortTest extends GreenmailTestCase
{
    private function seed(): string
    {
        $folderName = 'ImapSort'.random_int(10000, 99999);
        $client = $this->makeFolder($folderName);
        $folder = $client->getFolder($folderName);
        $folder->appendMessage("From: alice@example.com\r\nSubject: First message\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nBody 1");
        $folder->appendMessage("From: carol@example.com\r\nSubject: Re: Second one here\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\nBody 2 is a bit longer");
        $folder->appendMessage("From: bob@example.com\r\nSubject: Zzz last alphabetically\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nBody 3");

        return $folderName;
    }

    public function test_sort_by_date_ascending(): void
    {
        $connection = imap_open(self::mailboxSpec($this->seed()), self::USER, self::PASSWORD);

        $this->assertSame([3, 1, 2], imap_sort($connection, SORTDATE, false));

        imap_close($connection);
    }

    public function test_sort_by_date_descending(): void
    {
        $connection = imap_open(self::mailboxSpec($this->seed()), self::USER, self::PASSWORD);

        $this->assertSame([2, 1, 3], imap_sort($connection, SORTDATE, true));

        imap_close($connection);
    }

    public function test_sort_by_from(): void
    {
        $connection = imap_open(self::mailboxSpec($this->seed()), self::USER, self::PASSWORD);

        $this->assertSame([1, 3, 2], imap_sort($connection, SORTFROM, false));

        imap_close($connection);
    }

    public function test_sort_by_size(): void
    {
        $connection = imap_open(self::mailboxSpec($this->seed()), self::USER, self::PASSWORD);

        $this->assertSame([1, 3, 2], imap_sort($connection, SORTSIZE, false));

        imap_close($connection);
    }

    public function test_sort_with_se_uid_returns_uids(): void
    {
        $connection = imap_open(self::mailboxSpec($this->seed()), self::USER, self::PASSWORD);

        $msgnos = imap_sort($connection, SORTARRIVAL, false);
        $uids = imap_sort($connection, SORTARRIVAL, false, SE_UID);

        $this->assertSame($msgnos, $uids);

        imap_close($connection);
    }

    public function test_sort_with_search_criteria_restricts_results(): void
    {
        $connection = imap_open(self::mailboxSpec($this->seed()), self::USER, self::PASSWORD);

        $this->assertSame([2], imap_sort($connection, SORTDATE, false, 0, 'SUBJECT Second'));

        imap_close($connection);
    }

    /**
     * c-client hands sorting to any server advertising SORT, so the server's
     * ordering wins even where it deviates from RFC 5256: GreenMail compares
     * raw subjects, where a local sort would compare base subjects and put
     * "Re: Alpha" alongside "Alpha".
     */
    public function test_sortsubject_follows_the_servers_ordering(): void
    {
        $folderName = 'ImapSortSubject'.random_int(10000, 99999);
        $folder = $this->makeFolder($folderName)->getFolder($folderName);
        $folder->appendMessage("Subject: Re: Alpha\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\n1");
        $folder->appendMessage("Subject: Beta\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\n2");
        $folder->appendMessage("Subject: Alpha\r\nDate: Tue, 07 Jul 2026 12:00:00 +0000\r\n\r\n3");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame([3, 2, 1], imap_sort($connection, SORTSUBJECT, false));
        $this->assertSame([1, 2, 3], imap_sort($connection, SORTSUBJECT, true));

        imap_close($connection);
    }

    public function test_sort_invalid_criteria_throws(): void
    {
        $connection = imap_open(self::mailboxSpec($this->seed()), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);

        imap_sort($connection, 999, false);
    }

    public function test_sort_empty_mailbox_returns_empty_array(): void
    {
        $folderName = 'ImapSortEmpty'.random_int(10000, 99999);
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame([], imap_sort($connection, SORTDATE, false));

        imap_close($connection);
    }
}
