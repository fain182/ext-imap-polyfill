<?php

namespace ImapPolyfill\Tests\Integration;

class ImapSearchTest extends GreenmailTestCase
{
    public function test_returns_matching_message_numbers(): void
    {
        $folderName = 'SearchBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello World\r\n\r\nBody text");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame([1], imap_search($connection, 'ALL'));
    }

    public function test_returns_false_when_nothing_matches(): void
    {
        $folderName = 'SearchBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello World\r\n\r\nBody text");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertFalse(imap_search($connection, 'SUBJECT nonexistent'));
    }

    public function test_se_uid_returns_uids_instead_of_msgnos(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'SearchUidBox' . uniqid(),
            "Subject: Survivor\r\n\r\nKeep me"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame([1], imap_search($connection, 'ALL'));
        $this->assertSame([$survivorUid], imap_search($connection, 'ALL', SE_UID));
    }

    public function test_filters_by_flag_keywords(): void
    {
        $folderName = 'SearchBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $folder = $seedClient->getFolder($folderName);
        $folder->appendMessage("Subject: One\r\n\r\nBody 1");
        $folder->appendMessage("Subject: Two\r\n\r\nBody 2");
        $folder->appendMessage("Subject: Three\r\n\r\nBody 3");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);
        imap_setflag_full($connection, '2', '\\Flagged \\Seen');
        imap_delete($connection, '3');

        $this->assertSame([2], imap_search($connection, 'FLAGGED'));
        $this->assertSame([2], imap_search($connection, 'SEEN'));
        $this->assertSame([3], imap_search($connection, 'DELETED'));
        $this->assertSame([1, 3], imap_search($connection, 'UNSEEN'));
    }

    public function test_combines_multiple_single_word_criteria(): void
    {
        $folderName = 'SearchBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $folder = $seedClient->getFolder($folderName);
        $folder->appendMessage("Subject: Invoice\r\n\r\nBody 1");
        $folder->appendMessage("Subject: Invoice\r\n\r\nBody 2");
        $folder->appendMessage("Subject: Receipt\r\n\r\nBody 3");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);
        imap_setflag_full($connection, '1', '\\Flagged');

        // FLAGGED alone would already isolate message 1; asserting SUBJECT
        // Receipt (which message 1 does NOT match) returns nothing proves
        // the two criteria are actually ANDed, not just the first one applied.
        $this->assertSame([1], imap_search($connection, 'FLAGGED SUBJECT Invoice'));
        $this->assertFalse(imap_search($connection, 'FLAGGED SUBJECT Receipt'));
    }

    public function test_quoted_multi_word_subject_phrase(): void
    {
        $folderName = 'SearchBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Match Me\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame([1], imap_search($connection, 'SUBJECT "Match Me"'));
    }
}
