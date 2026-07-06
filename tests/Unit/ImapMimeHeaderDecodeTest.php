<?php

namespace ImapPolyfill\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ImapMimeHeaderDecodeTest extends TestCase
{
    public function test_decodes_a_single_encoded_word(): void
    {
        $result = imap_mime_header_decode('=?iso-8859-1?Q?Kilgore_Trout?=');

        $this->assertCount(1, $result);
        $this->assertSame('iso-8859-1', $result[0]->charset);
        $this->assertSame('Kilgore Trout', $result[0]->text);
    }

    public function test_plain_text_uses_default_charset(): void
    {
        $result = imap_mime_header_decode('Plain text');

        $this->assertCount(1, $result);
        $this->assertSame('default', $result[0]->charset);
        $this->assertSame('Plain text', $result[0]->text);
    }

    public function test_mixes_encoded_and_plain_segments(): void
    {
        $result = imap_mime_header_decode('=?UTF-8?B?SGVsbG8=?= World');

        $this->assertCount(2, $result);
        $this->assertSame('UTF-8', $result[0]->charset);
        $this->assertSame('Hello', $result[0]->text);
        $this->assertSame('default', $result[1]->charset);
        $this->assertSame(' World', $result[1]->text);
    }
}
