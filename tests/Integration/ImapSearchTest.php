<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

class ImapSearchTest extends GreenmailTestCase
{
    public function test_returns_matching_message_numbers(): void
    {
        $folderName = 'SearchBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello World\r\n\r\nBody text");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame([1], imap_search($connection, 'ALL'));
    }

    public function test_returns_false_when_nothing_matches(): void
    {
        $folderName = 'SearchBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello World\r\n\r\nBody text");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertFalse(imap_search($connection, 'SUBJECT nonexistent'));
    }

    public function test_se_uid_returns_uids_instead_of_msgnos(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'SearchUidBox' . uniqid(),
            "Subject: Survivor\r\n\r\nKeep me"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

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

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());
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

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());
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

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame([1], imap_search($connection, 'SUBJECT "Match Me"'));
    }

    /**
     * The criteria string is parsed into c-client's SEARCHPGM before anything
     * goes out, and that struct has no field for these — so they are refused
     * here rather than handed to a server that would have understood them.
     * IMAP's own SEARCH grammar is the wider one; imap_search()'s is this.
     *
     * @param non-empty-string $criteria
     */
    #[DataProvider('criteriaOutsideTheVocabulary')]
    public function test_a_criterion_c_client_cannot_build_fails_the_search(string $criteria, string $reported): void
    {
        $folderName = 'SearchBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Present\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        // The folder is not empty, so false is the refusal and not an
        // empty result set.
        $this->assertFalse(imap_search($connection, $criteria));
        $this->assertSame("Unknown search criterion: {$reported}", imap_last_error());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function criteriaOutsideTheVocabulary(): iterable
    {
        yield 'HEADER' => ['HEADER Subject Present', 'HEADER'];
        yield 'OR' => ['OR SEEN UNSEEN', 'OR'];
        yield 'NOT' => ['NOT SEEN', 'NOT'];
        yield 'LARGER' => ['LARGER 10', 'LARGER'];
        yield 'SMALLER' => ['SMALLER 10', 'SMALLER'];
        yield 'DRAFT' => ['DRAFT', 'DRAFT'];
        yield 'UNDRAFT' => ['UNDRAFT', 'UNDRAFT'];
        yield 'SENTSINCE' => ['SENTSINCE 1-Jan-2020', 'SENTSINCE'];
        yield 'a misspelling' => ['SUBJEKT x', 'SUBJEKT'];

        // Reported uppercased, however it was written.
        yield 'lowercase' => ['header Subject Present', 'HEADER'];

        // Keywords are split on spaces alone, and a value starts right after
        // its keyword's one space (mail_criteria_string()).
        yield 'a tab joins two keywords' => ["ALL\tSEEN", "ALL\tSEEN"];
        yield 'a value after two spaces' => ['SUBJECT  Present', 'SUBJECT'];
        yield 'an empty quoted value' => ['SUBJECT ""', 'SUBJECT'];
        yield 'an unclosed quote' => ['SUBJECT "Present', 'SUBJECT'];
        yield 'text behind the closing quote' => ['SUBJECT "Present"x', 'X'];

        // mail_parse_date(), which reads 49 as 1949.
        yield 'a two-digit year before 1970' => ['SINCE 1-Jan-49', 'SINCE'];
        yield 'junk behind the year' => ['SINCE 1-Jan-2020x', 'SINCE'];
    }

    /**
     * A criterion that takes a value and was given none is the same failure:
     * mail_criteria_string() answers NIL and the criterion is the one named.
     */
    public function test_a_criterion_missing_its_value_fails_the_search(): void
    {
        $folderName = 'SearchBox'.uniqid();
        $this->makeFolder($folderName)->getFolder($folderName)->appendMessage("Subject: Present\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertFalse(imap_search($connection, 'SUBJECT'));
        $this->assertSame('Unknown search criterion: SUBJECT', imap_last_error());
    }

    /**
     * imap_sort()'s search criteria go through the same parser, and a
     * keyword it cannot build fails the sort rather than quietly sorting
     * the whole folder.
     */
    public function test_the_same_vocabulary_gates_imap_sorts_search_criteria(): void
    {
        $folderName = 'SearchBox'.uniqid();
        $this->makeFolder($folderName)->getFolder($folderName)->appendMessage("Subject: Present\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertFalse(imap_sort($connection, SORTDATE, 0, 0, 'DRAFT'));
        $this->assertSame('Unknown search criterion: DRAFT', imap_last_error());

        // ...and a criterion it can build still sorts.
        $this->assertSame([1], imap_sort($connection, SORTDATE, 0, 0, 'UNSEEN'));
    }

    /**
     * What mail_criteria() reads that a whitespace split did not: a literal,
     * keywords in any case, and the dates mail_parse_date() knows.
     *
     * @param non-empty-string $criteria
     */
    #[DataProvider('criteriaCClientReads')]
    public function test_reads_criteria_the_way_mail_criteria_does(string $criteria, bool $matches): void
    {
        $folderName = 'SearchBox'.uniqid();
        $this->makeFolder($folderName)->getFolder($folderName)->appendMessage("Subject: Present tense\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());
        imap_errors();

        $this->assertSame($matches ? [1] : false, imap_search($connection, $criteria));
        $this->assertFalse(imap_errors());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function criteriaCClientReads(): iterable
    {
        yield 'a literal' => ["SUBJECT {13}\r\nPresent tense UNDELETED", true];
        yield 'a lowercase keyword' => ['subject Present', true];
        yield 'a quoted value' => ['SUBJECT "Present tense"', true];
        yield 'an ISO date' => ['SINCE 1970-01-02', true];
        yield 'a two-digit year' => ['BEFORE 1-Jan-70', false];
        yield 'a three-digit year' => ['SINCE 1-Jan-100', true];
        yield 'a day February does not have' => ['SINCE 31-Feb-2000', true];
        yield 'a date with a time' => ['SINCE "Sat, 1 Jan 2000 10:00:00 +0000"', true];
        yield 'an empty criteria string' => ['', true];
    }

    public function test_throws_value_error_for_an_invalid_flags_bitmask(): void
    {
        $folderName = 'SearchValBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Flags\r\n\r\nBody");
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_search(): Argument #3 ($flags) must be a bitmask of SE_FREE, and SE_UID');
        imap_search($connection, 'ALL', 0x40);
    }
}
