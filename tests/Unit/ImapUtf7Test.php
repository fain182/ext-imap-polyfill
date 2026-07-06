<?php

namespace ImapPolyfill\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ImapUtf7Test extends TestCase
{
    public function test_utf8_to_mutf7_and_back(): void
    {
        $utf8 = 'Posta in arrivo café';

        $mutf7 = imap_utf8_to_mutf7($utf8);

        $this->assertIsString($mutf7);
        $this->assertSame($utf8, imap_mutf7_to_utf8($mutf7));
    }

    public function test_ascii_only_string_is_unchanged_by_mutf7(): void
    {
        $this->assertSame('INBOX.Sent', imap_utf8_to_mutf7('INBOX.Sent'));
        $this->assertSame('INBOX.Sent', imap_mutf7_to_utf8('INBOX.Sent'));
    }

    public function test_utf7_decode_and_encode_round_trip_ascii(): void
    {
        $this->assertSame('INBOX.Sent', imap_utf7_encode('INBOX.Sent'));
        $this->assertSame('INBOX.Sent', imap_utf7_decode('INBOX.Sent'));
    }
}
