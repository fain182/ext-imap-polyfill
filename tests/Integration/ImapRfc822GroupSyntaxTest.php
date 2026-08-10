<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * RFC 822 group syntax, characterized against the real extension.
 *
 * imap_rfc822_parse_adrlist() needs no connection, so this whole class runs
 * unchanged under `make parity` — every assertion below was read off the
 * genuine extension before being written down.
 *
 * c-client reports a group as two extra entries around its members: one
 * carrying only the group name, one carrying nothing at all. Its handling
 * of what follows a closed group is a quirk rather than a rule, and is
 * pinned here for the same reason.
 */
final class ImapRfc822GroupSyntaxTest extends TestCase
{
    /**
     * @return array<string, array{string, array<int, array<string, string>>}>
     */
    public static function addressLists(): array
    {
        return [
            'empty group' => [
                'undisclosed-recipients:;',
                [['mailbox' => 'undisclosed-recipients'], []],
            ],
            'group with members' => [
                'Friends: alice@example.com, bob@example.com;',
                [
                    ['mailbox' => 'Friends'],
                    ['mailbox' => 'alice', 'host' => 'example.com'],
                    ['mailbox' => 'bob', 'host' => 'example.com'],
                    [],
                ],
            ],
            'group member keeps its personal name' => [
                'Team: "Bob B" <bob@example.com>;',
                [
                    ['mailbox' => 'Team'],
                    ['mailbox' => 'bob', 'host' => 'example.com', 'personal' => 'Bob B'],
                    [],
                ],
            ],
            'unterminated group is closed anyway' => [
                'Friends: alice@example.com',
                [['mailbox' => 'Friends'], ['mailbox' => 'alice', 'host' => 'example.com'], []],
            ],
            'a second group is refused' => [
                'A: x@e.com; B: y@e.com;',
                [
                    ['mailbox' => 'A'],
                    ['mailbox' => 'x', 'host' => 'e.com'],
                    [],
                    ['mailbox' => 'UNEXPECTED_DATA_AFTER_ADDRESS', 'host' => '.SYNTAX-ERROR.'],
                ],
            ],
            'so is a plain address after a group' => [
                'A: x@e.com; z@e.com',
                [
                    ['mailbox' => 'A'],
                    ['mailbox' => 'x', 'host' => 'e.com'],
                    [],
                    ['mailbox' => 'UNEXPECTED_DATA_AFTER_ADDRESS', 'host' => '.SYNTAX-ERROR.'],
                ],
            ],
            'a terminator with no group open is malformed' => [
                ';',
                [['mailbox' => 'INVALID_ADDRESS', 'host' => '.SYNTAX-ERROR.']],
            ],
            'route syntax is refused rather than parsed' => [
                '@relay.example.com:user@example.com',
                [['mailbox' => 'INVALID_ADDRESS', 'host' => '.SYNTAX-ERROR.']],
            ],
            // The quoting comes off the group name like any other word, so
            // a backslash in it quotes rather than stands.
            'escape in the group name' => [
                'undis\\closed-recipients:',
                [['mailbox' => 'undisclosed-recipients'], []],
            ],
            // Whatever is in front of the colon is the name, however little
            // it looks like one.
            'name that is not a phrase anyone meant' => [
                "=?UTF-8?B?YQ==?==== :Joe\n <joe@example.com>",
                [
                    ['mailbox' => '=?UTF-8?B?YQ==?===='],
                    ['mailbox' => 'joe', 'host' => 'example.com', 'personal' => 'Joe'],
                    [],
                ],
            ],
            // Inside a group the two failures have their own markers, and
            // the group is still closed after them.
            'leftover after a member' => [
                'Date:== Mon',
                [
                    ['mailbox' => 'Date'],
                    ['mailbox' => '==', 'host' => 'default.host'],
                    ['mailbox' => 'UNEXPECTED_DATA_AFTER_ADDRESS_IN_GROUP', 'host' => '.SYNTAX-ERROR.'],
                    [],
                ],
            ],
            'member that will not parse' => [
                'A: <;',
                [
                    ['mailbox' => 'A'],
                    ['mailbox' => 'INVALID_ADDRESS_IN_GROUP', 'host' => '.SYNTAX-ERROR.'],
                    [],
                ],
            ],
            'unterminated route address in a group' => [
                'A: <b',
                [
                    ['mailbox' => 'A'],
                    ['mailbox' => 'b', 'host' => 'default.host'],
                    ['mailbox' => 'MISSING_MAILBOX_TERMINATOR', 'host' => '.SYNTAX-ERROR.'],
                    [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function complaintsMadeInsideAGroup(): array
    {
        return [
            'leftover after a member' => [
                'Date:== Mon',
                'Unexpected characters after address in group: Mon',
            ],
            'member that will not parse' => [
                'A: <;',
                'Invalid group mailbox list: <;',
            ],
        ];
    }

    /**
     * The same two failures read differently inside a group: c-client has
     * its own wording and its own markers there, and using the ones from
     * outside makes a header look like it ended where it did not.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('complaintsMadeInsideAGroup')]
    public function test_a_complaint_inside_a_group_says_so(string $list, string $error): void
    {
        imap_errors();

        @imap_rfc822_parse_adrlist($list, 'default.host');

        $this->assertSame([$error], imap_errors());
    }

    /**
     * @param array<int, array<string, string>> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('addressLists')]
    public function test_group_syntax_matches_the_real_extension(string $list, array $expected): void
    {
        $parsed = @imap_rfc822_parse_adrlist($list, 'default.host');

        $this->assertCount(count($expected), $parsed);

        foreach ($expected as $index => $fields) {
            // Fields c-client left unset are absent, not null — visible
            // through property_exists() and every dump of the object.
            $this->assertSame($fields, get_object_vars($parsed[$index]), "entry {$index}");
        }
    }
}
