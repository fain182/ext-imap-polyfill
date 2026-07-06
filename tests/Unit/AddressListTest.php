<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Address\AddressList;
use PHPUnit\Framework\TestCase;

class AddressListTest extends TestCase
{
    public function test_first_as_string_returns_null_for_empty_input(): void
    {
        $this->assertNull(AddressList::parse('', 'example.com')->firstAsString());
    }

    public function test_first_as_string_formats_personal_name_and_address(): void
    {
        $this->assertSame(
            'Joe Doe <joe@example.com>',
            AddressList::parse('Joe Doe <joe@example.com>, jane@example.com', 'example.com')->firstAsString()
        );
    }

    public function test_first_as_string_without_personal_name(): void
    {
        $this->assertSame(
            'jane@example.com',
            AddressList::parse('jane@example.com', 'example.com')->firstAsString()
        );
    }

    public function test_parse_skips_malformed_entries(): void
    {
        // A bare "<" has no mailbox characters at all, so it can't match the
        // address grammar and is dropped rather than producing a bogus entry.
        $this->assertSame([], AddressList::parse('<', 'example.com')->toLegacyArray());
    }

    public function test_parse_returns_empty_array_for_empty_string(): void
    {
        $this->assertSame([], AddressList::parse('', 'example.com')->toLegacyArray());
    }
}
