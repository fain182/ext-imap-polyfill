<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Pure-PHP characterization of imap_8bit()'s rfc822_8bit behaviors that
 * PHP's own quoted_printable_encode() gets differently; needs no Greenmail
 * but lives in the integration suite so the parity job checks every
 * expected string against the genuine extension's c-client output.
 */
class Imap8bitTest extends TestCase
{
    public function test_quotes_the_equals_sign(): void
    {
        $this->assertSame('Hello=3DWorld', imap_8bit('Hello=World'));
    }

    public function test_quotes_tabs(): void
    {
        $this->assertSame('a=09b', imap_8bit("a\tb"));
    }

    public function test_quotes_8bit_bytes(): void
    {
        $this->assertSame('caff=E8', imap_8bit("caff\xE8"));
    }

    public function test_passes_crlf_through_and_quotes_the_space_before_it(): void
    {
        $this->assertSame("line=20\r\nnext", imap_8bit("line \r\nnext"));
    }

    public function test_keeps_a_trailing_space_at_end_of_input(): void
    {
        $this->assertSame('end ', imap_8bit('end '));
    }

    public function test_quotes_a_bare_line_feed(): void
    {
        $this->assertSame('a=0Ab', imap_8bit("a\nb"));
    }

    public function test_soft_breaks_at_75_characters(): void
    {
        $this->assertSame(
            str_repeat('a', 75)."=\r\n".str_repeat('a', 5),
            imap_8bit(str_repeat('a', 80))
        );
    }
}
