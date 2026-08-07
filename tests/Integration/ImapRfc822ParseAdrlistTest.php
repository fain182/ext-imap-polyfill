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
}
