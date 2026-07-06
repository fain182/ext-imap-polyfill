<?php

namespace Fain182\ImapPolyfill\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ImapUtf8Test extends TestCase
{
    public function test_passes_through_unencoded_text(): void
    {
        $this->assertSame('standard text', imap_utf8('standard text'));
    }

    public function test_decodes_quoted_printable_encoded_word(): void
    {
        $this->assertSame('Kilgore Trout', imap_utf8('=?iso-8859-1?Q?Kilgore_Trout?='));
    }

    public function test_decodes_base64_encoded_word(): void
    {
        $this->assertSame('éé', imap_utf8('=?UTF-8?B?w6nDqQ==?='));
    }
}
