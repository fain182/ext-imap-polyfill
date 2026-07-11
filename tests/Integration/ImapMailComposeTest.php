<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Pure-PHP characterization of imap_mail_compose(); needs no Greenmail but
 * lives in the integration suite so the parity job checks every expected
 * string against the genuine extension's c-client output.
 */
class ImapMailComposeTest extends TestCase
{
    public function test_composes_a_simple_text_message(): void
    {
        $result = imap_mail_compose([
            'date' => 'Fri, 11 Jul 2026 12:00:00 +0000',
            'from' => 'john@example.com',
            'to' => 'jane@example.com',
            'subject' => 'Hello',
            'message_id' => '<msg1@example.com>',
        ], [
            [
                'type' => TYPETEXT,
                'subtype' => 'PLAIN',
                'charset' => 'ISO-8859-1',
                'contents.data' => 'Ciao',
            ],
        ]);

        $this->assertSame(
            "Date: Fri, 11 Jul 2026 12:00:00 +0000\r\n"
            ."From: john@example.com\r\n"
            ."Subject: Hello\r\n"
            ."To: jane@example.com\r\n"
            ."Message-ID: <msg1@example.com>\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: TEXT/PLAIN; CHARSET=ISO-8859-1\r\n"
            ."\r\n"
            ."Ciao\r\n",
            $result
        );
    }

    public function test_defaults_to_text_plain_us_ascii_when_only_contents_are_given(): void
    {
        $result = imap_mail_compose(['subject' => 'Bare'], [['contents.data' => 'body']]);

        $this->assertSame(
            "Subject: Bare\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: TEXT/PLAIN; CHARSET=US-ASCII\r\n"
            ."\r\n"
            ."body\r\n",
            $result
        );
    }

    public function test_quotes_a_personal_phrase_containing_specials(): void
    {
        $result = imap_mail_compose(
            ['to' => 'John Q. Public <john@example.com>'],
            [['contents.data' => 'x']]
        );

        $this->assertIsString($result);
        $this->assertStringContainsString("To: \"John Q. Public\" <john@example.com>\r\n", $result);
    }

    public function test_folds_a_long_address_list_after_78_characters(): void
    {
        $a = str_repeat('a', 20).'@example.com';
        $b = str_repeat('b', 20).'@example.com';
        $c = str_repeat('c', 20).'@example.com';
        $d = str_repeat('d', 20).'@example.com';

        $result = imap_mail_compose(['to' => "{$a}, {$b}, {$c}, {$d}"], [['contents.data' => 'x']]);

        $this->assertIsString($result);
        // The ", " separator lands before the fold, the continuation is 4 spaces.
        $this->assertStringContainsString("To: {$a}, {$b}, {$c}, \r\n    {$d}\r\n", $result);
    }

    public function test_writes_undisclosed_recipients_when_only_bcc_is_given(): void
    {
        $result = imap_mail_compose(
            ['bcc' => 'secret@example.com', 'subject' => 'Ssh'],
            [['contents.data' => 'x']]
        );

        $this->assertIsString($result);
        $this->assertStringContainsString("To: undisclosed recipients: ;\r\n", $result);
        $this->assertStringNotContainsString('secret@example.com', $result);
    }

    public function test_composes_a_multipart_message_with_a_user_supplied_boundary(): void
    {
        $result = imap_mail_compose(['subject' => 'Multi'], [
            [
                'type' => TYPEMULTIPART,
                'type.parameters' => ['BOUNDARY' => '=-=-='],
            ],
            [
                'type' => TYPETEXT,
                'contents.data' => 'part one',
            ],
            [
                'type' => TYPEAPPLICATION,
                'subtype' => 'OCTET-STREAM',
                'encoding' => ENCBINARY,
                'contents.data' => 'BIN',
            ],
        ]);

        $this->assertSame(
            "Subject: Multi\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: MULTIPART/MIXED; BOUNDARY=\"=-=-=\"\r\n"
            ."\r\n"
            ."--=-=-=\r\n"
            ."Content-Type: TEXT/PLAIN; CHARSET=US-ASCII\r\n"
            ."\r\n"
            ."part one\r\n"
            ."--=-=-=\r\n"
            ."Content-Type: APPLICATION/OCTET-STREAM\r\n"
            ."Content-Transfer-Encoding: BASE64\r\n"
            ."\r\n"
            ."QklO\r\n"
            ."\r\n"
            ."--=-=-=--\r\n",
            $result
        );
    }

    public function test_generates_a_boundary_when_none_is_supplied(): void
    {
        $result = imap_mail_compose([], [
            ['type' => TYPEMULTIPART],
            ['contents.data' => 'one'],
            ['contents.data' => 'two'],
        ]);

        $this->assertIsString($result);
        // c-client's cookie is "hostid-random-time=:pid"; "=" and ":" are
        // tspecials, so it always comes out quoted.
        $this->assertMatchesRegularExpression(
            '/Content-Type: MULTIPART\/MIXED; BOUNDARY="\d+-\d+-\d+=:\d+"\r\n/',
            $result
        );
    }

    public function test_reencodes_an_8bit_body_as_quoted_printable_with_unknown_charset(): void
    {
        $result = imap_mail_compose([], [
            ['type' => TYPETEXT, 'encoding' => ENC8BIT, 'contents.data' => 'plain ascii'],
        ]);

        $this->assertSame(
            "MIME-Version: 1.0\r\n"
            ."Content-Type: TEXT/PLAIN; CHARSET=X-UNKNOWN\r\n"
            ."Content-Transfer-Encoding: QUOTED-PRINTABLE\r\n"
            ."\r\n"
            ."plain ascii\r\n",
            $result
        );
    }

    public function test_appends_custom_headers_in_reverse_order(): void
    {
        $result = imap_mail_compose([
            'subject' => 'Custom',
            'custom_headers' => ['X-One: 1', 'X-Two: 2'],
        ], [['contents.data' => 'x']]);

        $this->assertSame(
            "Subject: Custom\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: TEXT/PLAIN; CHARSET=US-ASCII\r\n"
            ."X-Two: 2\r\n"
            ."X-One: 1\r\n"
            ."\r\n"
            ."x\r\n",
            $result
        );
    }

    public function test_remail_prefixes_headers_with_resent_and_drops_the_mime_headers(): void
    {
        // Since the header-injection hardening, a remail value may not
        // contain a line break that isn't a fold — so it cannot end with
        // CRLF, and c-client writes it verbatim, gluing the next header
        // onto the same line.
        $result = imap_mail_compose([
            'remail' => 'X-Orig: relay',
            'subject' => 'Fwd',
            'to' => 'jane@example.com',
        ], [['contents.data' => 'x']]);

        $this->assertSame(
            'X-Orig: relay'
            ."ReSent-Subject: Fwd\r\n"
            ."ReSent-To: jane@example.com\r\n"
            ."\r\n"
            ."x\r\n",
            $result
        );
    }

    public function test_rejects_a_remail_value_ending_with_a_bare_crlf(): void
    {
        $this->assertFalse(@imap_mail_compose(['remail' => "X-Orig: relay\r\n"], [['contents.data' => 'x']]));
    }

    public function test_message_rfc822_body_ignores_contents_data(): void
    {
        $result = imap_mail_compose([], [
            ['type' => TYPEMESSAGE, 'subtype' => 'RFC822', 'contents.data' => 'ignored'],
        ]);

        $this->assertSame(
            "MIME-Version: 1.0\r\n"
            ."Content-Type: MESSAGE/RFC822\r\n"
            ."\r\n"
            ."\r\n",
            $result
        );
    }

    public function test_writes_content_disposition_id_and_description(): void
    {
        $result = imap_mail_compose([], [
            [
                'type' => TYPEAPPLICATION,
                'subtype' => 'OCTET-STREAM',
                'id' => '<part1@example.com>',
                'description' => 'an attachment',
                'disposition.type' => 'attachment',
                'disposition' => ['filename' => 'data bin.dat'],
                'contents.data' => 'x',
            ],
        ]);

        $this->assertSame(
            "MIME-Version: 1.0\r\n"
            ."Content-Type: APPLICATION/OCTET-STREAM\r\n"
            ."Content-ID: <part1@example.com>\r\n"
            ."Content-Description: an attachment\r\n"
            ."Content-Disposition: attachment; filename=\"data bin.dat\"\r\n"
            ."\r\n"
            ."x\r\n",
            $result
        );
    }

    public function test_throws_a_value_error_for_an_empty_bodies_array(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_mail_compose(): Argument #2 ($bodies) cannot be empty');

        imap_mail_compose([], []);
    }

    public function test_throws_a_type_error_when_the_first_body_is_not_an_array(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage(
            'imap_mail_compose(): Argument #2 ($bodies) individual body must be of type array, string given'
        );

        imap_mail_compose([], ['not an array']);
    }

    public function test_throws_a_value_error_when_the_first_body_is_an_empty_array(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_mail_compose(): Argument #2 ($bodies) individual body cannot be empty');

        imap_mail_compose([], [[]]);
    }

    public function test_returns_false_for_a_multipart_body_without_components(): void
    {
        $this->assertFalse(@imap_mail_compose([], [['type' => TYPEMULTIPART]]));
    }

    public function test_returns_false_and_warns_on_a_header_injection_attempt(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        });

        try {
            $result = imap_mail_compose(['subject' => "Bad\r\nX-Evil: 1"], [['contents.data' => 'x']]);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('header injection attempt in subject', $warnings[0]);
    }

    public function test_allows_a_folded_header_value(): void
    {
        $result = imap_mail_compose(['subject' => "Long\r\n subject"], [['contents.data' => 'x']]);

        $this->assertIsString($result);
        $this->assertStringContainsString("Subject: Long\r\n subject\r\n", $result);
    }
}
