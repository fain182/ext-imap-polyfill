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

    public function test_decodes_utf8_to_precomposed_form_not_decomposed(): void
    {
        // Regression: mb_decode_mimeheader() has been observed to return
        // NFD (decomposed, "e" + combining acute accent) on some platforms
        // instead of the NFC (precomposed) bytes the base64 payload actually
        // encodes — same glyphs, different bytes. Must not depend on mbstring's
        // internal engine for a same-charset (UTF-8 to UTF-8) decode.
        $this->assertSame('c3a9c3a9', bin2hex(imap_utf8('=?UTF-8?B?w6nDqQ==?=')));
    }

    public function test_joins_adjacent_encoded_words_dropping_the_gap(): void
    {
        $this->assertSame('HelloWorld', imap_utf8('=?UTF-8?B?SGVsbG8=?= =?UTF-8?B?V29ybGQ=?='));
    }
}
