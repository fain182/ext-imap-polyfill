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
}
