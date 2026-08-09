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
}
