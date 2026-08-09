<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Message\SearchKey;
use ImapPolyfill\Message\SearchProgram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The criteria parser, against c-client's mail_criteria() (mail.c). The
 * refusals it produces are checked through imap_search() in
 * tests/Integration/ImapSearchTest; these pin the parse itself.
 */
class SearchProgramTest extends TestCase
{
    public function test_reads_a_run_of_keywords(): void
    {
        $program = SearchProgram::parse('UNSEEN FLAGGED DELETED');

        $this->assertSame(
            [SearchKey::Unseen, SearchKey::Flagged, SearchKey::Deleted],
            array_map(static fn ($c) => $c->key, $program->criteria),
        );
    }

    public function test_reads_the_value_of_a_keyword_that_takes_one(): void
    {
        $program = SearchProgram::parse('SUBJECT Invoice FROM alice@example.com');

        $this->assertSame(SearchKey::Subject, $program->criteria[0]->key);
        $this->assertSame('Invoice', $program->criteria[0]->argument);
        $this->assertSame(SearchKey::From, $program->criteria[1]->key);
        $this->assertSame('alice@example.com', $program->criteria[1]->argument);
    }

    public function test_a_quoted_value_keeps_its_spaces(): void
    {
        $program = SearchProgram::parse('SUBJECT "Match Me Please"');

        $this->assertSame('Match Me Please', $program->criteria[0]->argument);
    }

    public function test_keywords_are_case_insensitive(): void
    {
        $program = SearchProgram::parse('unseen subject Invoice');

        $this->assertSame(SearchKey::Unseen, $program->criteria[0]->key);
        $this->assertSame(SearchKey::Subject, $program->criteria[1]->key);
    }

    /**
     * The tokens travel on to the server untouched — they have been checked
     * against the vocabulary, not rewritten.
     */
    public function test_keeps_the_tokens_for_the_wire(): void
    {
        $this->assertSame(['UNSEEN', 'SUBJECT', 'Invoice'], SearchProgram::parse('UNSEEN SUBJECT Invoice')->tokens);
    }

    public function test_an_empty_criteria_string_is_an_empty_program(): void
    {
        $program = SearchProgram::parse('');

        $this->assertSame([], $program->criteria);
    }

    /**
     * @param non-empty-string $criteria
     */
    #[DataProvider('refused')]
    public function test_refuses_what_mail_criteria_cannot_build(string $criteria, string $reported): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown search criterion: {$reported}");

        SearchProgram::parse($criteria);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'HEADER' => ['HEADER Subject x', 'HEADER'];
        yield 'OR' => ['OR SEEN UNSEEN', 'OR'];
        yield 'NOT' => ['NOT SEEN', 'NOT'];
        yield 'LARGER' => ['LARGER 10', 'LARGER'];
        yield 'DRAFT' => ['DRAFT', 'DRAFT'];
        yield 'SENTON' => ['SENTON 1-Jan-2020', 'SENTON'];
        yield 'a value with no keyword' => ['Invoice', 'INVOICE'];
        yield 'a keyword with no value' => ['SUBJECT', 'SUBJECT'];
        yield 'the second keyword' => ['UNSEEN BOGUS', 'BOGUS'];
    }

    /**
     * A date the search program cannot hold fails as the criterion itself,
     * not as a bad value: c-client's shortdate packs the year into 7 bits
     * above 1970, so 1970 and 2097 are the ends of what fits.
     *
     * @param non-empty-string $criteria
     */
    #[DataProvider('dates')]
    public function test_a_date_outside_what_c_client_can_hold_fails_the_criterion(string $criteria, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(\RuntimeException::class);
        }

        $program = SearchProgram::parse($criteria);

        $this->assertCount(1, $program->criteria);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function dates(): iterable
    {
        yield 'the first year that fits' => ['SINCE 1-Jan-1970', true];
        yield 'the last year that fits' => ['SINCE 1-Jan-2097', true];
        yield 'an ISO date' => ['BEFORE 2026-01-05', true];

        yield 'a year below the range' => ['SINCE 1-Jan-1969', false];
        yield 'a year above it' => ['SINCE 1-Jan-2098', false];
        yield 'a day that does not exist' => ['SINCE 32-Jan-2026', false];
        yield 'a month that does not exist' => ['SINCE 1-Foo-2026', false];
        yield 'not a date at all' => ['SINCE garbage', false];

        // The date is one space-delimited token, so this one fails on "5"
        // and never reaches "Jan".
        yield 'a date written with spaces' => ['BEFORE 5 Jan 2026', false];
    }

    /**
     * "%.30s" in c-client's message, and it is the criterion that is
     * truncated, not the line.
     */
    public function test_a_long_criterion_is_truncated_at_thirty_characters(): void
    {
        $criterion = str_repeat('X', 40);

        $this->expectExceptionMessage('Unknown search criterion: '.str_repeat('X', 30));

        SearchProgram::parse($criterion);
    }
}
