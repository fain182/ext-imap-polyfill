<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\ResetsErrorStack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The imap_* functions that take no connection, characterized against the
 * real extension.
 *
 * They used to be asserted only in tests/Unit — against this polyfill's own
 * assumptions, never against ext-imap. Comparing them turned up eight
 * divergences at once: silently decoding invalid base64, mangling malformed
 * modified UTF-7 instead of failing, unquoted personal names, a missing
 * parse error, the wrong close timeout, and an envelope whose properties
 * came out in a different order. Needing no server, all of it runs under
 * `make parity`.
 */
final class PureFunctionsTest extends TestCase
{
    use ResetsErrorStack;

    /**
     * @return array<string, array{string, string|false}>
     */
    public static function base64Inputs(): array
    {
        return [
            'padded' => ['aGVsbG8=', 'hello'],
            'unpadded' => ['aGVsbG8', 'hello'],
            'folded' => ["aGVs\nbG8=", 'hello'],
            'outside the alphabet' => ['aGVs!!bG8=', false],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('base64Inputs')]
    public function test_base64(string $input, string|false $expected): void
    {
        $this->assertSame($expected, imap_base64($input));
    }

    /**
     * @return array<string, array{string, array<int, array{string, string}>|false}>
     */
    public static function mimeHeaders(): array
    {
        return [
            'base64 segment' => ['=?UTF-8?B?Y2lhbw==?=', [['UTF-8', 'ciao']]],
            'quoted printable segment' => ['=?ISO-8859-1?Q?caf=E9?=', [['ISO-8859-1', "caf\xE9"]]],
            'no encoding at all' => ['plain text', [['default', 'plain text']]],
            // Any single character is accepted as the encoding; only B and Q
            // are acted on, the rest leave the data as it stands.
            'unknown encoding passes through' => ['=?UTF-8?X?Y2lhbw==?=', [['UTF-8', 'Y2lhbw==']]],
            // One undecodable segment fails the whole call.
            'undecodable base64 fails outright' => ['=?UTF-8?B?bad!!base64?=', false],
            'segments keep their surroundings' => [
                'a =?UTF-8?B?Yg==?= c',
                [['default', 'a '], ['UTF-8', 'b'], ['default', ' c']],
            ],
        ];
    }

    /**
     * @param array<int, array{string, string}>|false $expected
     */
    #[DataProvider('mimeHeaders')]
    public function test_mime_header_decode(string $input, array|false $expected): void
    {
        $decoded = imap_mime_header_decode($input);

        if ($expected === false) {
            $this->assertFalse($decoded);

            return;
        }

        $this->assertIsArray($decoded);
        $this->assertSame(
            $expected,
            array_map(static fn (\stdClass $part): array => [$part->charset, $part->text], $decoded),
        );
    }

    /**
     * @return array<string, array{string, string|false}>
     */
    public static function modifiedUtf7(): array
    {
        return [
            'plain ascii' => ['Ciao', 'Ciao'],
            'escaped ampersand' => ['&-', '&'],
            // mb_convert_encoding() answers with mangled text here; c-client
            // refuses, and so must these.
            'unterminated run' => ['&AOk', false],
            'trailing ampersand' => ['INBOX&', false],
        ];
    }

    #[DataProvider('modifiedUtf7')]
    public function test_mutf7_to_utf8(string $input, string|false $expected): void
    {
        $this->assertSame($expected, imap_mutf7_to_utf8($input));
    }

    #[DataProvider('modifiedUtf7')]
    public function test_utf7_decode(string $input, string|false $expected): void
    {
        $this->assertSame($expected, imap_utf7_decode($input));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function addresses(): array
    {
        return [
            'plain' => ['box', 'host', '', 'box@host'],
            'with personal' => ['box', 'host', 'Jane Doe', 'Jane Doe <box@host>'],
            'personal holding a comma' => ['m', 'h', 'Doe, Jane', '"Doe, Jane" <m@h>'],
            // The standalone function used to quote on a comma alone, so a
            // double quote came through unescaped and broke the header.
            'personal holding a quote' => ['m', 'h', 'a"b', '"a\\"b" <m@h>'],
        ];
    }

    #[DataProvider('addresses')]
    public function test_rfc822_write_address(string $mailbox, string $host, string $personal, string $expected): void
    {
        $this->assertSame($expected, imap_rfc822_write_address($mailbox, $host, $personal));
    }

    /**
     * The envelope's property order is observable — through foreach,
     * get_object_vars() and every dump — and each address list is preceded
     * by its raw "*address" string rather than followed by it.
     */
    public function test_rfc822_parse_headers_property_order(): void
    {
        $headers = "From: a@b.com\r\nTo: c@d.com\r\nCc: e@f.com\r\n"
            ."Reply-To: i@j.com\r\nSubject: Ciao\r\n"
            ."Date: Tue, 07 Jul 2026 10:00:00 +0000\r\nMessage-ID: <x@y>\r\n\r\n";

        $this->assertSame(
            [
                'date', 'Date', 'subject', 'Subject', 'message_id',
                'toaddress', 'to', 'fromaddress', 'from',
                'ccaddress', 'cc', 'reply_toaddress', 'reply_to',
                'senderaddress', 'sender',
            ],
            array_keys(get_object_vars(imap_rfc822_parse_headers($headers))),
        );
    }

    public function test_qprint_reports_a_bad_sequence_without_refusing_the_text(): void
    {
        $this->assertSame('a=ZZb', imap_qprint('a=ZZb'));
        $this->assertSame('Invalid quoted-printable sequence: =ZZb', imap_last_error());
    }

    /**
     * c-client has no separate close timeout, so it never reports one.
     */
    public function test_close_timeout_is_zero(): void
    {
        $this->assertSame(0, imap_timeout(IMAP_CLOSETIMEOUT));
    }
}
