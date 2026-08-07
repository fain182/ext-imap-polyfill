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
        ];
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
