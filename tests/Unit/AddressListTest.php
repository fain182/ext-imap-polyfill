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

    /**
     * Taken from the real extension: a malformed entry is reported in-band,
     * not dropped. An earlier version of this test asserted the opposite,
     * which was this polyfill's own invention.
     */
    public function test_parse_reports_a_malformed_entry_in_band(): void
    {
        $parsed = AddressList::parse('<', 'example.com')->toLegacyArray();

        $this->assertCount(1, $parsed);
        $this->assertSame('INVALID_ADDRESS', $parsed[0]->mailbox);
        $this->assertSame('.SYNTAX-ERROR.', $parsed[0]->host);
    }

    public function test_parse_keeps_the_addresses_read_before_a_malformed_one(): void
    {
        $parsed = AddressList::parse('a@b, <', 'example.com')->toLegacyArray();

        $this->assertCount(2, $parsed);
        $this->assertSame('a', $parsed[0]->mailbox);
        $this->assertSame('INVALID_ADDRESS', $parsed[1]->mailbox);
    }

    public function test_parse_returns_empty_array_for_empty_string(): void
    {
        $this->assertSame([], AddressList::parse('', 'example.com')->toLegacyArray());
    }
}
