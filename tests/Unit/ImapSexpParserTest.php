<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Search\ImapSexpParser;
use PHPUnit\Framework\TestCase;

class ImapSexpParserTest extends TestCase
{
    public function test_parses_flat_list_of_atoms_and_quoted_strings(): void
    {
        $buffer = '("text" "plain" 7BIT 10)';

        $this->assertSame(['text', 'plain', '7BIT', 10], ImapSexpParser::parseAt($buffer, 0));
    }

    public function test_maps_nil_to_null(): void
    {
        $buffer = '("text" NIL nil 10)';

        $this->assertSame(['text', null, null, 10], ImapSexpParser::parseAt($buffer, 0));
    }

    public function test_parses_nested_lists(): void
    {
        $buffer = '(("charset" "us-ascii") NIL)';

        $this->assertSame([['charset', 'us-ascii'], null], ImapSexpParser::parseAt($buffer, 0));
    }

    public function test_handles_escaped_quotes_inside_quoted_strings(): void
    {
        $buffer = '("say \"hi\"" 1)';

        $this->assertSame(['say "hi"', 1], ImapSexpParser::parseAt($buffer, 0));
    }

    public function test_parses_a_realistic_bodystructure_response(): void
    {
        $buffer = '("text" "plain" ("charset" "us-ascii") NIL NIL "7BIT" 10 1 NIL NIL NIL)';

        $this->assertSame(
            ['text', 'plain', ['charset', 'us-ascii'], null, null, '7BIT', 10, 1, null, null, null],
            ImapSexpParser::parseAt($buffer, 0)
        );
    }

    public function test_parses_starting_at_an_offset_within_a_larger_buffer(): void
    {
        $buffer = '* 1 FETCH (BODYSTRUCTURE ("text" "plain" NIL NIL NIL "7BIT" 5))';
        $pos = strpos($buffer, '(', strpos($buffer, 'BODYSTRUCTURE'));

        $this->assertSame(['text', 'plain', null, null, null, '7BIT', 5], ImapSexpParser::parseAt($buffer, $pos));
    }

    public function test_parses_literal_syntax(): void
    {
        $buffer = "({5}\r\nhello 1)";

        $this->assertSame(['hello', 1], ImapSexpParser::parseAt($buffer, 0));
    }

    public function test_parses_literal_syntax_with_bare_lf(): void
    {
        $buffer = "({5}\nhello 1)";

        $this->assertSame(['hello', 1], ImapSexpParser::parseAt($buffer, 0));
    }

    public function test_throws_on_unterminated_quoted_string(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unterminated quoted string in IMAP response');

        ImapSexpParser::parseAt('("unterminated', 0);
    }
}
