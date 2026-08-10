<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Backslash escapes inside a quoted personal name, characterized against
 * the real extension.
 *
 * Like the group-syntax class next to it, imap_rfc822_parse_adrlist() needs
 * no connection, so all of this runs unchanged under `make parity` — every
 * expectation below was read off the genuine extension first.
 *
 * A quoted-string is the one place an address may carry the characters that
 * otherwise delimit the list, and c-client strips the backslash that lets
 * them in. Getting this wrong doesn't mangle one name, it fails the address
 * outright and takes the rest of the header down with it.
 */
final class ImapRfc822ParseAdrlistTest extends TestCase
{
    /**
     * @return array<string, array{string, array<int, array<string, string>>}>
     */
    public static function quotedPersonalNames(): array
    {
        return [
            // The property order is c-client's own, and assertSame holds it
            // to that: mailbox and host first, personal last.
            'plain quoted name' => [
                '"Simple Name" <a@b.com>',
                [['mailbox' => 'a', 'host' => 'b.com', 'personal' => 'Simple Name']],
            ],
            // A colon is group syntax outside quotes and ordinary text in.
            'colon inside the quotes' => [
                '"This one: is right" <ding@dong.com>',
                [['mailbox' => 'ding', 'host' => 'dong.com', 'personal' => 'This one: is right']],
            ],
            'escaped quote inside the quotes' => [
                '"This one: is \"right\"" <ding@dong.com>',
                [['mailbox' => 'ding', 'host' => 'dong.com', 'personal' => 'This one: is "right"']],
            ],
            'escaped backslash inside the quotes' => [
                '"Back \\\\ slash" <a@b.com>',
                [['mailbox' => 'a', 'host' => 'b.com', 'personal' => 'Back \\ slash']],
            ],
            // The escape must not swallow the closing quote: the addresses
            // after it have to survive too.
            'escaped quote followed by another address' => [
                '"This one: is \"right\"" <ding@dong.com>, No-address',
                [
                    ['mailbox' => 'ding', 'host' => 'dong.com', 'personal' => 'This one: is "right"'],
                    ['mailbox' => 'No-address', 'host' => 'default.host'],
                ],
            ],
            // A comma inside the quotes separates nothing, and an escaped
            // quote must not hand it back to the list.
            'escaped quote hiding a comma' => [
                '"say \"hi\", ok" <a@b.com>, b@c.com',
                [
                    ['mailbox' => 'a', 'host' => 'b.com', 'personal' => 'say "hi", ok'],
                    ['mailbox' => 'b', 'host' => 'c.com'],
                ],
            ],
            // An empty name is still a name once it has been written down:
            // the empty string is set, where an absent name sets nothing.
            'name written as empty' => [
                '"" <a@b.com>',
                [['mailbox' => 'a', 'host' => 'b.com', 'personal' => '']],
            ],
            // The name does not simply run to the end of the input.
            'quote that never closes' => [
                '"unterminated <a@b.com>',
                [['mailbox' => 'INVALID_ADDRESS', 'host' => '.SYNTAX-ERROR.']],
            ],
            // A backslash quotes what follows it outside a quoted string too:
            // rfc822_parse_word() reads past both, and rfc822_quote() drops
            // the backslash without ever asking whether it was in quotes.
            'escape outside the quotes' => [
                '=\\=',
                [['mailbox' => '==', 'host' => 'default.host']],
            ],
            'escaped at sign' => [
                'a\\@b.com',
                [['mailbox' => 'a@b.com', 'host' => 'default.host']],
            ],
        ];
    }

    /**
     * @param array<int, array<string, string>> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('quotedPersonalNames')]
    public function test_quoted_personal_names_match_the_real_extension(string $list, array $expected): void
    {
        $parsed = @imap_rfc822_parse_adrlist($list, 'default.host');

        $this->assertCount(count($expected), $parsed);

        foreach ($expected as $index => $fields) {
            $this->assertSame($fields, get_object_vars($parsed[$index]), "entry {$index}");
        }
    }

    /**
     * @return array<string, array{string, array<int, array<string, string>>}>
     */
    public static function phrasesWithoutAnAddress(): array
    {
        $unexpected = ['mailbox' => 'UNEXPECTED_DATA_AFTER_ADDRESS', 'host' => '.SYNTAX-ERROR.'];

        return [
            // The quoted string is one word, so all of it becomes the mailbox.
            'quoted phrase before empty brackets' => [
                '"Undisclosed Recipients" <>',
                [['mailbox' => 'Undisclosed Recipients', 'host' => 'default.host'], $unexpected],
            ],
            // Unquoted, the phrase is several words and only the first is
            // read: an addr-spec has no way to spend the second.
            'bare phrase before empty brackets' => [
                'Undisclosed Recipients <>',
                [['mailbox' => 'Undisclosed', 'host' => 'default.host'], $unexpected],
            ],
            'brackets holding only an at sign' => [
                '"a b" <@>',
                [['mailbox' => 'a b', 'host' => 'default.host'], $unexpected],
            ],
            // The addresses after it are lost either way — c-client punts the
            // rest of the list once it has marked one — but which entry comes
            // back first is not the same.
            'more addresses after the empty brackets' => [
                '"A" <>, b@c.com',
                [['mailbox' => 'A', 'host' => 'default.host'], $unexpected],
            ],
            // No brackets in sight: two words with nothing between them end
            // the address at the first, wherever the "@" happens to be.
            'two words and an address' => [
                'foo bar@x.com',
                [['mailbox' => 'foo', 'host' => 'default.host'], $unexpected],
            ],
            // Dots are the one thing that continues a mailbox, and the
            // whitespace written around them is not part of it.
            'dots continue the mailbox' => [
                'a . b@x.com',
                [['mailbox' => 'a.b', 'host' => 'x.com']],
            ],
            // Nothing to fall back to: an empty route-addr with no phrase in
            // front of it is the malformed-list marker, not a mailbox.
            'empty brackets on their own' => [
                '<>',
                [['mailbox' => 'INVALID_ADDRESS', 'host' => '.SYNTAX-ERROR.']],
            ],
        ];
    }

    /**
     * A phrase followed by angle brackets that hold no address at all.
     * c-client does not give up there: rfc822_parse_mailbox() starts the
     * parse again from the top as a plain addr-spec, so the phrase becomes
     * the mailbox — one word of it — and the brackets become trailing data.
     * Reading the whole thing as one failed address instead loses the name
     * *and* every address written after it.
     *
     * @param array<int, array<string, string>> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('phrasesWithoutAnAddress')]
    public function test_a_phrase_falls_back_to_being_the_mailbox(string $list, array $expected): void
    {
        $parsed = @imap_rfc822_parse_adrlist($list, 'default.host');

        $this->assertCount(count($expected), $parsed);

        foreach ($expected as $index => $fields) {
            $this->assertSame($fields, get_object_vars($parsed[$index]), "entry {$index}");
        }
    }

    /**
     * @return array<string, array{string, array<string, string>, string|false}>
     */
    public static function domains(): array
    {
        return [
            // A word may hold dots — they are not among the delimiters
            // rfc822_parse_word() stops at — so none of these is the parser
            // giving up partway.
            'two dots running' => ['a@b..c', ['mailbox' => 'a', 'host' => 'b..c'], false],
            'leading dot' => ['a@.b', ['mailbox' => 'a', 'host' => '.b'], false],
            'trailing dot' => ['a@b.', ['mailbox' => 'a', 'host' => 'b.'], false],
            // Whitespace around the dots is not part of the domain.
            'space before the domain' => ['a@ b.com', ['mailbox' => 'a', 'host' => 'b.com'], false],
            'tab inside the domain' => ["a@b\t.com", ['mailbox' => 'a', 'host' => 'b.com'], false],
            // Kept with its brackets: they are what says the text between
            // them is not a name to look up anywhere.
            'domain literal' => ['a@[1.2.3.4]', ['mailbox' => 'a', 'host' => '[1.2.3.4]'], false],
            // No domain at all keeps the address and marks the host, rather
            // than quietly reading the default host into it.
            'nothing after the at sign' => [
                'a@',
                ['mailbox' => 'a', 'host' => '.SYNTAX-ERROR.'],
                'Missing or invalid host name after @',
            ],
            'empty domain literal' => [
                'a@[]',
                ['mailbox' => 'a', 'host' => '.SYNTAX-ERROR.'],
                'Empty domain literal',
            ],
            'unterminated domain literal' => [
                'a@[1.2.3.4',
                ['mailbox' => 'a', 'host' => '.SYNTAX-ERROR.'],
                'Unterminated domain literal',
            ],
        ];
    }

    /**
     * @param array<string, string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('domains')]
    public function test_domains_are_read_as_rfc822_parse_domain_reads_them(
        string $list,
        array $expected,
        string|false $error,
    ): void {
        imap_errors();

        $parsed = @imap_rfc822_parse_adrlist($list, 'default.host');

        $this->assertSame($expected, get_object_vars($parsed[0]));
        $this->assertSame($error === false ? [] : [$error], imap_errors() ?: []);
    }

    /**
     * A comment that never closes is reported, once, and takes the rest of
     * the string with it: rfc822_skip_comment() writes a NUL over its "(" so
     * that a second pass over the same text neither parses what was in it
     * nor reports it again. A list holding nothing else is an empty list,
     * not a malformed one — the address parser never gets that far, since
     * comments are whitespace and whitespace is skipped before it looks.
     */
    public function test_an_unterminated_comment_is_reported_once(): void
    {
        imap_errors();

        $parsed = @imap_rfc822_parse_adrlist('j(e', 'default.host');

        $this->assertSame(
            [['mailbox' => 'j', 'host' => 'default.host']],
            array_map(get_object_vars(...), $parsed),
        );
        $this->assertSame(['Unterminated comment: (e'], imap_errors());
    }

    /**
     * @return array<string, array{string, int, string|false}>
     */
    public static function commasWithNothingBehindThem(): array
    {
        // [list, how many entries come back, what lands on the stack]
        return [
            // The comma is consumed, what follows it is not skipped before
            // the parser is asked again — so whitespace after the last comma
            // reaches the parser and fails there.
            'whitespace after the last comma' => ['a@b.com, ', 2, 'Missing address after comma'],
            // Nothing at all behind it, or another comma, is eaten with the
            // whitespace around it and never reaches the parser.
            'nothing after the last comma' => ['a@b.com,', 1, false],
            'two commas' => ['a@b.com,,', 1, false],
            'leading comma' => [', ', 0, false],
            // What the complaint quotes is what was left, so the commas that
            // were eaten are not in it.
            'leading comma before a bad address' => [',\\', 1, 'Invalid mailbox list: \\'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('commasWithNothingBehindThem')]
    public function test_a_comma_with_no_address_behind_it(string $list, int $entries, string|false $error): void
    {
        imap_errors();

        $this->assertCount($entries, @imap_rfc822_parse_adrlist($list, 'default.host'));
        $this->assertSame($error === false ? [] : [$error], imap_errors() ?: []);
    }

    public function test_a_list_holding_only_a_comment_is_empty(): void
    {
        imap_errors();

        $this->assertSame([], @imap_rfc822_parse_adrlist('(only a comment)', 'default.host'));
        $this->assertSame([], imap_errors() ?: []);

        $this->assertSame([], @imap_rfc822_parse_adrlist('(u', 'default.host'));
        $this->assertSame(['Unterminated comment: (u'], imap_errors());
    }

    /**
     * Which complaint c-client makes about what it could not use depends on
     * the first character of it: something alphanumeric reads as an address
     * missing its comma, anything else as debris.
     */
    public function test_the_complaint_depends_on_what_was_left_over(): void
    {
        imap_errors();

        @imap_rfc822_parse_adrlist('"Undisclosed Recipients" <>', 'default.host');
        $this->assertSame(['Unexpected characters at end of address: <>'], imap_errors());

        @imap_rfc822_parse_adrlist('Undisclosed Recipients <>', 'default.host');
        $this->assertSame(['Must use comma to separate addresses: Recipients <>'], imap_errors());
    }

    /**
     * Nothing has to separate an unquoted personal name from the address
     * it belongs to: the angle bracket ends it by itself. Mail in the wild
     * is written this way — an encoded word butted straight against the
     * address — and reading it as one unparseable token loses the address
     * as well as the name.
     */
    public function test_a_personal_name_needs_no_space_before_the_angle_bracket(): void
    {
        $parsed = @imap_rfc822_parse_adrlist('=?X-IAS-German?B?bXlHb3Y=?=<info@bla.bla>', 'default.host');

        $this->assertCount(1, $parsed);
        $this->assertSame(
            ['mailbox' => 'info', 'host' => 'bla.bla', 'personal' => '=?X-IAS-German?B?bXlHb3Y=?='],
            get_object_vars($parsed[0]),
        );
    }

    /**
     * A route-addr: the source route c-client's rfc822_parse_routeaddr()
     * reads off the front and keeps in adl, brackets and colon gone but the
     * leading "@" and any separating commas kept. Not reading it lost the
     * address it introduces and, with it, every address after it in the
     * header.
     */
    public function test_a_source_route_becomes_the_adl_field(): void
    {
        $parsed = imap_rfc822_parse_adrlist('<@route.example.com:foo@example.ac.uk>', 'default.host');

        $this->assertCount(1, $parsed);
        $this->assertSame(
            ['mailbox' => 'foo', 'host' => 'example.ac.uk', 'adl' => '@route.example.com'],
            get_object_vars($parsed[0]),
        );
    }

    public function test_a_source_route_may_name_more_than_one_host(): void
    {
        $parsed = imap_rfc822_parse_adrlist('<@one.example.com,@two.example.com:foo@example.ac.uk>', 'default.host');

        $this->assertSame(
            ['mailbox' => 'foo', 'host' => 'example.ac.uk', 'adl' => '@one.example.com,@two.example.com'],
            get_object_vars($parsed[0]),
        );
    }

    /**
     * A route-addr in the middle of a list is read like any other address,
     * and the ones after it are still read.
     */
    public function test_a_source_route_does_not_end_the_list(): void
    {
        $parsed = imap_rfc822_parse_adrlist('<@route.example.com:foo@example.ac.uk>, second@example.com', 'default.host');

        $this->assertCount(2, $parsed);
        $this->assertSame(
            ['mailbox' => 'second', 'host' => 'example.com'],
            get_object_vars($parsed[1]),
        );
    }

    /**
     * c-client keeps the address it managed to read, marks the rest, and
     * says what the rest was.
     */
    public function test_trailing_data_is_named_on_the_error_stack(): void
    {
        // Drained rather than reset: the stack is process-global under both
        // implementations, and only one of them can be reset from PHP.
        imap_errors();

        $parsed = imap_rfc822_parse_adrlist('ian@one@two', 'default.host');

        $this->assertSame(['mailbox' => 'ian', 'host' => 'one'], get_object_vars($parsed[0]));
        $this->assertSame(
            ['mailbox' => 'UNEXPECTED_DATA_AFTER_ADDRESS', 'host' => '.SYNTAX-ERROR.'],
            get_object_vars($parsed[1]),
        );
        $this->assertSame(['Unexpected characters at end of address: @two'], imap_errors());
    }

    /**
     * What was left over is quoted as it stands, to the end of the string.
     * A comma in it separates nothing once the parse has given up, so it is
     * part of the complaint rather than the start of another address.
     */
    public function test_the_leftover_text_is_quoted_to_the_end_of_the_string(): void
    {
        imap_errors();

        imap_rfc822_parse_adrlist('a@b.co[m,', 'default.host');

        $this->assertSame(['Unexpected characters at end of address: [m,'], imap_errors());
    }

    /**
     * A comment inside a comment, neither of them closed. c-client reports
     * the inner one and writes a NUL over its "(", which leaves the outer
     * one unterminated against the shortened text — so the next pass reports
     * that too, and the two complaints come out innermost first.
     */
    public function test_nested_unterminated_comments_are_reported_from_the_inside_out(): void
    {
        imap_errors();

        $parsed = @imap_rfc822_parse_adrlist('Joe (the (big', 'default.host');

        $this->assertSame(
            [['mailbox' => 'Joe', 'host' => 'default.host']],
            array_map(get_object_vars(...), $parsed),
        );
        $this->assertSame(
            ['Unterminated comment: (big', 'Unterminated comment: (the '],
            imap_errors(),
        );
    }

    /**
     * @return array<string, array{string, array<int, array<string, string>>, string}>
     */
    public static function routeAddressesMissingTheirBracket(): array
    {
        return [
            'no name in front of it' => [
                '<a',
                [
                    ['mailbox' => 'a', 'host' => 'default.host'],
                    ['mailbox' => 'MISSING_MAILBOX_TERMINATOR', 'host' => '.SYNTAX-ERROR.'],
                ],
                'Unterminated mailbox: a@default.host',
            ],
            'a name in front of it' => [
                'Joe <a@b.com',
                [
                    ['mailbox' => 'a', 'host' => 'b.com', 'personal' => 'Joe'],
                    ['mailbox' => 'MISSING_MAILBOX_TERMINATOR', 'host' => '.SYNTAX-ERROR.'],
                ],
                'Unterminated mailbox: a@b.com',
            ],
        ];
    }

    /**
     * An address opened with "<" and never closed is kept, and followed by
     * the marker saying so — rather than read as though the bracket had
     * been there.
     *
     * @param array<int, array<string, string>> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeAddressesMissingTheirBracket')]
    public function test_an_unterminated_route_address_is_marked(string $list, array $expected, string $error): void
    {
        imap_errors();

        $parsed = @imap_rfc822_parse_adrlist($list, 'default.host');

        $this->assertSame($expected, array_map(get_object_vars(...), $parsed));
        $this->assertSame([$error], imap_errors());
    }

    /**
     * rfc822_parse_domain() leaves the caller's pointer where it found it
     * when there is no domain to read, so the comment it looked through on
     * the way is still there for rfc822_parse_addrspec() to read as the
     * personal name.
     */
    public function test_a_comment_becomes_the_name_when_the_domain_is_missing(): void
    {
        $parsed = @imap_rfc822_parse_adrlist('<joe@(the host))example.com>', 'default.host');

        $this->assertSame(
            ['mailbox' => 'joe', 'host' => '.SYNTAX-ERROR.', 'personal' => 'the host'],
            get_object_vars($parsed[0]),
        );
    }
}
