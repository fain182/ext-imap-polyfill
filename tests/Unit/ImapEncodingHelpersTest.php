<?php

namespace ImapPolyfill\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ImapEncodingHelpersTest extends TestCase
{
    public function test_imap_base64_decodes(): void
    {
        $this->assertSame('Hello World', imap_base64(base64_encode('Hello World')));
    }

    public function test_imap_qprint_decodes(): void
    {
        $this->assertSame('Hello=World', imap_qprint('Hello=3DWorld'));
    }

    public function test_imap_8bit_encodes_to_quoted_printable(): void
    {
        $this->assertSame('Hello=3DWorld', imap_8bit('Hello=World'));
    }

    public function test_imap_binary_encodes_to_base64(): void
    {
        $this->assertSame(base64_encode('Hello World'), rtrim(imap_binary('Hello World')));
    }

    public function test_imap_binary_wraps_long_output_at_60_chars_per_line(): void
    {
        $text = str_repeat('a', 100);

        $result = imap_binary($text);

        $lines = explode("\n", rtrim($result, "\n"));
        $this->assertSame(60, strlen($lines[0]));
        $this->assertSame(base64_encode($text), implode('', $lines));
    }
}
