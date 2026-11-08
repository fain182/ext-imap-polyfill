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
     * mail_criteria_string()'s three forms of a value, as the extension was
     * measured reading them.
     *
     * @param array{SearchKey, ?string}[] $expected
     */
    #[DataProvider('values')]
    public function test_reads_values_the_way_mail_criteria_string_does(string $criteria, array $expected): void
    {
        $program = SearchProgram::parse($criteria);

        $this->assertSame($expected, array_map(
            static fn ($criterion): array => [$criterion->key, $criterion->argument],
            $program->criteria,
        ));
    }

    /**
     * @return iterable<string, array{string, array{SearchKey, ?string}[]}>
     */
    public static function values(): iterable
    {
        yield 'a literal' => ["SUBJECT {7}\r\nfoo bar UNSEEN", [[SearchKey::Subject, 'foo bar'], [SearchKey::Unseen, null]]];
        yield 'an empty literal' => ["SUBJECT {0}\r\n UNSEEN", [[SearchKey::Subject, ''], [SearchKey::Unseen, null]]];
        yield 'a literal length strtoul reads' => ["SUBJECT { +3}\r\nfoo", [[SearchKey::Subject, 'foo']]];
        yield 'quotes run to the next quote' => ['SUBJECT ""foo bar" SEEN', [[SearchKey::Subject, 'foo bar'], [SearchKey::Seen, null]]];
        yield 'leading quotes are skipped' => ['SUBJECT "" UNSEEN', [[SearchKey::Subject, ' UNSEEN']]];
        yield 'a quote inside an atom is text' => ['SUBJECT foo"bar', [[SearchKey::Subject, 'foo"bar']]];
        yield 'a NUL ends the criteria' => ["SUBJECT foo\0 UNSEEN", [[SearchKey::Subject, 'foo']]];
        yield 'a date is written the way the command carries it' => ['BEFORE 2026-01-06', [[SearchKey::Before, '6-Jan-2026']]];
        yield 'a two-digit year' => ['SINCE 1-Jan-26', [[SearchKey::Since, '1-Jan-2026']]];
        yield 'a three-digit year' => ['SINCE 1-Jan-126', [[SearchKey::Since, '1-Jan-2026']]];
        yield 'a day the month does not have' => ['ON 31-Feb-2026', [[SearchKey::On, '31-Feb-2026']]];
        yield 'a compact date with day 0' => ['ON 20260100', [[SearchKey::On, '0-Jan-2026']]];
        yield 'a quoted date with a time' => ['BEFORE "Mon, 5 Jan 2026 10:00:00 +0000"', [[SearchKey::Before, '5-Jan-2026']]];
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
        yield 'a tab is part of the keyword' => ["ALL\tSEEN", "ALL\tSEEN"];
        yield 'a value after two spaces' => ['SUBJECT  foo', 'SUBJECT'];
        yield 'an empty quoted value' => ['SUBJECT ""', 'SUBJECT'];
        yield 'an unclosed quote' => ['SUBJECT "foo', 'SUBJECT'];
        yield 'text behind the closing quote' => ['SUBJECT "foo bar"x', 'X'];
        yield 'a literal longer than what follows' => ["SUBJECT {9}\r\nfoo", 'SUBJECT'];
        yield 'a literal without its CRLF' => ["SUBJECT {3}\nfoo", 'SUBJECT'];
        yield 'a literal followed by more than a space' => ["SUBJECT {3}\r\nfoox", 'SUBJECT'];
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

        yield 'a two-digit year read as 1949' => ['SINCE 1-Jan-49', false];
        yield 'a time that is not one' => ['BEFORE "5 Jan 2026 25:00"', false];
        yield 'junk behind the year' => ['BEFORE 6-Jan-2026x', false];
        yield 'a layout c-client does not read' => ['BEFORE 2026/01/06', false];
        yield 'the one date that packs to 0' => ['SINCE 19700000', false];
        yield 'a month of 0' => ['SINCE 20260006', false];
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
