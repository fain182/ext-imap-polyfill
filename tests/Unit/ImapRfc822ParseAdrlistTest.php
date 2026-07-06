<?php

namespace Fain182\ImapPolyfill\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ImapRfc822ParseAdrlistTest extends TestCase
{
    public function test_parses_single_address_with_personal_name(): void
    {
        $result = imap_rfc822_parse_adrlist('Joe Doe <joe@example.com>', 'example.com');

        $this->assertCount(1, $result);
        $this->assertSame('joe', $result[0]->mailbox);
        $this->assertSame('example.com', $result[0]->host);
        $this->assertSame('Joe Doe', $result[0]->personal);
    }

    public function test_parses_multiple_comma_separated_addresses(): void
    {
        $result = imap_rfc822_parse_adrlist('Joe Doe <joe@example.com>, Jane Doe <jane@example.com>', 'example.com');

        $this->assertCount(2, $result);
        $this->assertSame('jane', $result[1]->mailbox);
        $this->assertSame('example.com', $result[1]->host);
        $this->assertSame('Jane Doe', $result[1]->personal);
    }

    public function test_address_without_personal_name_has_no_personal_property(): void
    {
        $result = imap_rfc822_parse_adrlist('foo@example.com', 'example.com');

        $this->assertSame('foo', $result[0]->mailbox);
        $this->assertSame('example.com', $result[0]->host);
        $this->assertFalse(property_exists($result[0], 'personal'));
    }

    public function test_bare_mailbox_without_host_uses_default_hostname(): void
    {
        $result = imap_rfc822_parse_adrlist('foo', 'example.com');

        $this->assertSame('foo', $result[0]->mailbox);
        $this->assertSame('example.com', $result[0]->host);
    }

    public function test_quoted_personal_name_containing_comma(): void
    {
        $result = imap_rfc822_parse_adrlist('"Doe, Joe" <joe@example.com>', 'example.com');

        $this->assertCount(1, $result);
        $this->assertSame('joe', $result[0]->mailbox);
        $this->assertSame('Doe, Joe', $result[0]->personal);
    }
}
