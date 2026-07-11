<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Pins the numeric values of the registered constants to what the real
 * extension defines (c-client mail.h, except CL_EXPUNGE which is ext-imap's
 * own PHP_EXPUNGE), for user code that hardcoded the numbers. Runs in the
 * integration suite (no server needed) so the parity job verifies the
 * expected values against the genuine extension.
 */
class ImapConstantsTest extends TestCase
{
    private const VALUES = [
        'NIL' => 0,
        'IMAP_OPENTIMEOUT' => 1,
        'IMAP_READTIMEOUT' => 2,
        'IMAP_WRITETIMEOUT' => 3,
        'IMAP_CLOSETIMEOUT' => 4,
        'OP_DEBUG' => 0x1,
        'OP_READONLY' => 0x2,
        'OP_ANONYMOUS' => 0x4,
        'OP_SHORTCACHE' => 0x8,
        'OP_SILENT' => 0x10,
        'OP_PROTOTYPE' => 0x20,
        'OP_HALFOPEN' => 0x40,
        'OP_EXPUNGE' => 0x80,
        'OP_SECURE' => 0x100,
        // ext-imap's PHP_EXPUNGE, deliberately not c-client's CL_EXPUNGE (0x1)
        // so it can't collide with the OP_* bitmask in imap_open().
        'CL_EXPUNGE' => 32768,
        'FT_UID' => 0x1,
        'FT_PEEK' => 0x2,
        'FT_INTERNAL' => 0x8,
        'ST_UID' => 0x1,
        'CP_UID' => 0x1,
        'CP_MOVE' => 0x2,
        'SE_UID' => 0x1,
        'SE_FREE' => 0x2,
        'SA_MESSAGES' => 0x1,
        'SA_RECENT' => 0x2,
        'SA_UNSEEN' => 0x4,
        'SA_UIDNEXT' => 0x8,
        'SA_UIDVALIDITY' => 0x10,
        'SA_ALL' => 0x1F,
        'TYPETEXT' => 0,
        'TYPEMULTIPART' => 1,
        'TYPEMESSAGE' => 2,
        'TYPEAPPLICATION' => 3,
        'TYPEAUDIO' => 4,
        'TYPEIMAGE' => 5,
        'TYPEVIDEO' => 6,
        'TYPEMODEL' => 7,
        'TYPEOTHER' => 8,
        'ENC7BIT' => 0,
        'ENC8BIT' => 1,
        'ENCBINARY' => 2,
        'ENCBASE64' => 3,
        'ENCQUOTEDPRINTABLE' => 4,
        'ENCOTHER' => 5,
        'LATT_NOINFERIORS' => 0x1,
        'LATT_NOSELECT' => 0x2,
        'LATT_MARKED' => 0x4,
        'LATT_UNMARKED' => 0x8,
        'LATT_REFERRAL' => 0x10,
        'LATT_HASCHILDREN' => 0x20,
        'LATT_HASNOCHILDREN' => 0x40,
    ];

    public function test_constant_values_match_the_real_extension(): void
    {
        foreach (self::VALUES as $name => $value) {
            $this->assertTrue(defined($name), "{$name} is not defined");
            $this->assertSame($value, constant($name), "{$name} diverges from the real extension's value");
        }
    }
}
