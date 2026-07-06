<?php

namespace Fain182\ImapPolyfill\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ImapRfc822WriteAddressTest extends TestCase
{
    public function test_formats_with_personal_name(): void
    {
        $this->assertSame('Joe Doe <joe@example.com>', imap_rfc822_write_address('joe', 'example.com', 'Joe Doe'));
    }

    public function test_formats_without_personal_name(): void
    {
        $this->assertSame('joe@example.com', imap_rfc822_write_address('joe', 'example.com', ''));
    }

    public function test_quotes_personal_name_containing_a_comma(): void
    {
        $this->assertSame('"Doe, Joe" <joe@example.com>', imap_rfc822_write_address('joe', 'example.com', 'Doe, Joe'));
    }
}
