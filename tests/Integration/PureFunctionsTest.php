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
            // RFC 2047 §6.2: whitespace *between* two encoded words is
            // folding, not content, and disappears with them. Only there —
            // the surroundings case above keeps the spaces that border
            // ordinary text.
            'space between adjacent segments is dropped' => [
                '=?UTF-8?B?YQ==?= =?UTF-8?B?Yg==?=',
                [['UTF-8', 'a'], ['UTF-8', 'b']],
            ],
            'so is a tab, and so is a run of them' => [
                "=?UTF-8?B?YQ==?=\t =?UTF-8?B?Yg==?=",
                [['UTF-8', 'a'], ['UTF-8', 'b']],
            ],
            // No text is no parts. c-client emits one part per run it finds
            // and finds none here, where a part carrying the empty string
            // would say the header held something.
            'nothing decodes to nothing' => ['', []],
            // php_imap.c scans for the three separators in turn rather than
            // matching a shape: the encoding is whatever byte follows the
            // second "?", and the text begins three past it whether or not
            // anything else stood in between.
            'encoding two characters wide' => ['=?UTF-8?\\B?Y?=', [['UTF-8', '?Y']]],
            // A run of "=" before one is not part of it, and the "=?" that
            // opens nothing is text.
            'equals signs before the opening' => ['===?', [['default', '=='], ['default', '=?']]],
            // One separator missing leaves the rest undecodable, and it
            // comes back as it stands rather than being dropped.
            'no encoding separator' => ['=?x?', [['default', '=?x?']]],
            'no closing separator' => ['=?x?y?', [['default', '=?x?y?']]],
            // Unlike imap_utf8()'s reader, this one has no opinion about
            // what follows the word.
            'followed by a bracket' => ['=?UTF-8?B?YQ==?=]', [['UTF-8', 'a'], ['default', ']']]],
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
    public static function base64Payloads(): array
    {
        return [
            'well formed' => ['Zm9v', 'foo'],
            // Not PHP's strict base64_decode(): a quantum left incomplete
            // with no padding is legal, and the bits that do not fill a byte
            // are dropped rather than refusing the string.
            'one character' => ['a', ''],
            'two characters' => ['ab', 'i'],
            'empty' => ['', ''],
            'whitespace is skipped' => ["Zm9v\n", 'foo'],
            'padding in the wrong place' => ['a=b', false],
            'padding mid-quantum' => ['Zm=9v', false],
            'character outside the alphabet' => ['~~', false],
        ];
    }

    #[DataProvider('base64Payloads')]
    public function test_base64_decodes_the_way_rfc822_base64_does(string $input, string|false $expected): void
    {
        $this->assertSame($expected, imap_base64($input));
    }

    public function test_base64_keeps_what_it_read_before_data_after_the_padding(): void
    {
        imap_errors();

        $this->assertSame('foob', imap_base64('Zm9vYg==extra'));
        $this->assertSame(['Possible data truncation in rfc822_base64(): extra'], imap_errors());
    }

    /**
     * The quoted-printable inside an encoded word is read by two different
     * decoders. imap_mime_header_decode() hands it to rfc822_qprint(), which
     * reports a "=" with no hex pair behind it and reads on — where the
     * reader inside utf8_mime2text() refuses the word outright. The
     * underscores are spaces by the time the report is written.
     */
    public function test_a_bad_quoted_printable_sequence_is_reported_by_name(): void
    {
        imap_errors();

        $decoded = imap_mime_header_decode('=?UTF-8?Q?a=ZZ_b?=');

        $this->assertIsArray($decoded);
        $this->assertSame('a=ZZ b', $decoded[0]->text);
        $this->assertSame(['Invalid quoted-printable sequence: =ZZ b'], imap_errors());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function undecodableWords(): array
    {
        // 49 characters of payload before the padding: the first "=" lands in
        // the second position of a quantum, where rfc822_base64() has no way
        // to read it and refuses the string.
        $misplacedPadding = '=?UTF-8?B?nnDusSNdG92w6Fuw61fMjAxOF8wMy0xMzMyNTMzMTkzLnBkZg==?=';

        return [
            'padding in the wrong place' => [$misplacedPadding, $misplacedPadding],
            // One "=" is enough in the last position of a quantum, but never
            // in the second-to-last.
            'lone padding in the third position' => ['=?UTF-8?B?YWJjZA=?=', '=?UTF-8?B?YWJjZA=?='],
            'character outside the alphabet' => ['=?UTF-8?B?YWJ!jZA==?=', '=?UTF-8?B?YWJ!jZA==?='],
            // mime2_decode() reads B and Q; the encoding being anything else
            // is a syntax error, not a word to leave alone.
            'unknown encoding' => ['=?UTF-8?X?aGk=?=', '=?UTF-8?X?aGk=?='],
            // Inside an encoded word "=" must introduce two hex digits. This
            // is not rfc822_qprint(), which reports and reads on.
            'quoted-printable without the hex digits' => ['=?UTF-8?Q?a=ZZb?=', '=?UTF-8?Q?a=ZZb?='],
            // A quantum left incomplete without any padding is legal, and the
            // bits that do not fill a byte are dropped.
            'incomplete final quantum' => ['=?UTF-8?B?aGVsbG9z5?=', 'hellos'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function wordsThatAreNotWords(): array
    {
        return [
            'on its own' => ['=?UTF-8?B?YQ==?=', 'a'],
            'between spaces' => ['x =?UTF-8?B?YQ==?= y', 'x a y'],
            // A word has to be a whitespace-delimited token. One that merely
            // starts inside another is ordinary text, and c-client skips to
            // the next space rather than looking again.
            'starting inside a token' => ['x=?UTF-8?B?YQ==?=', 'x=?UTF-8?B?YQ==?='],
            'in brackets' => ['(=?UTF-8?B?YQ==?=)', '(=?UTF-8?B?YQ==?=)'],
            // And it has to end one: mime2_text() requires whitespace or the
            // end of the text behind the "?=".
            'followed by a bracket' => ['=?UTF-8?B?YQ==?=]', '=?UTF-8?B?YQ==?=]'],
            'followed by a comma' => ['=?UTF-8?B?YQ==?=,', '=?UTF-8?B?YQ==?=,'],
            'followed by an address' => ['=?UTF-8?B?YQ==?=<a@b>', '=?UTF-8?B?YQ==?=<a@b>'],
            // The charset is a mime2_token(): none of RFC 822's specials, and
            // "=" is one of them.
            'charset holding an equals' => ['=?UTF-=8?B?YQ==?=', '=?UTF-=8?B?YQ==?='],
            'charset holding a space' => ['=?a b?B?YQ==?=', '=?a b?B?YQ==?='],
            'encoding two characters wide' => ['=?UTF-8?BB?YQ==?=', '=?UTF-8?BB?YQ==?='],
            // An empty payload is still a word, and decodes to nothing.
            'empty payload' => ['=?UTF-8?B??=', ''],
            // MINENCWORD: fewer than ten characters from the word's start to
            // the end of the text and c-client does not look.
            'shorter than MINENCWORD' => ['=?a?B??=', '=?a?B??='],
            'long enough' => ['=?a?B?eHk?=', 'xy'],
        ];
    }

    #[DataProvider('wordsThatAreNotWords')]
    public function test_what_counts_as_an_encoded_word(string $input, string $expected): void
    {
        $this->assertSame($expected, imap_utf8($input));
    }

    /**
     * utf8_mime2text() hands back src untouched the moment mime2_decode()
     * refuses a word — so a word that will not decode does not merely stay
     * as it is, it voids the whole call, including the words converted
     * before it.
     */
    #[DataProvider('undecodableWords')]
    public function test_a_word_that_will_not_decode_voids_the_call(string $input, string $expected): void
    {
        $this->assertSame($expected, imap_utf8($input));

        $this->assertSame(
            $expected === $input ? '=?UTF-8?B?b2s=?= '.$input : 'ok'.$expected,
            imap_utf8('=?UTF-8?B?b2s=?= '.$input),
        );
    }

    /**
     * Data after complete padding is the one base64 fault c-client reads
     * past: it keeps what it had and says so.
     */
    public function test_data_after_the_padding_is_reported_not_refused(): void
    {
        imap_errors();

        $this->assertSame('hello', imap_utf8('=?UTF-8?B?aGVsbG8=extra?='));
        $this->assertSame(
            ['Possible data truncation in rfc822_base64(): extra?='],
            imap_errors(),
        );

        $decoded = imap_mime_header_decode('=?UTF-8?B?aGVsbG8=extra?=');

        $this->assertIsArray($decoded);
        $this->assertSame('hello', $decoded[0]->text);
        // php_imap.c hands the decoder the word's data on its own, so the
        // same warning quotes less of the header than imap_utf8()'s does.
        $this->assertSame(
            ['Possible data truncation in rfc822_base64(): extra'],
            imap_errors(),
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
     * @return array<string, array{string, string|false, string|false}>
     */
    public static function modifiedUtf7RoundTrips(): array
    {
        return [
            // A control character is ASCII, and utf8_to_mutf7() shifts into a
            // BASE64 run for the high bit and for nothing else — where
            // mbstring's UTF7-IMAP escapes anything unprintable.
            'tab' => ["\t", "\t", "\t"],
            'newline' => ["\r\n", "\r\n", "\r\n"],
            'ampersand' => ['&', '&-', false],
            'escaped ampersand' => ['&-', '&--', '&'],
            'accented latin' => ["caff\u{e8}", 'caff&AOg-', false],
            'a run' => ['&Jjo-', '&-Jjo-', "\u{263a}"],
            // Refused rather than substituted: utf8_get() answering an error
            // is c-client returning NIL, and an octet with the high bit set
            // is "reserved for future use with UTF-8" on the way back.
            'invalid utf-8' => ["\xff", false, false],
            // Half a surrogate pair is not text: c-client's converter writes
            // nothing where mbstring would write a substitute character.
            'half a surrogate pair' => ['&2D3es-', '&-2D3es-', ''],
        ];
    }

    #[DataProvider('modifiedUtf7RoundTrips')]
    public function test_modified_utf7_conversions(string $input, string|false $encoded, string|false $decoded): void
    {
        $this->assertSame($encoded, imap_utf8_to_mutf7($input));
        $this->assertSame($decoded, imap_mutf7_to_utf8($input));
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
            // A mailbox and a host go by rfc822_output_cat()'s rule, not the
            // personal name's: a dot is ordinary in the middle and forces
            // quoting at either end or doubled, and a space always does.
            'host with a dot at each end' => ['a', '.SYNTAX-ERROR.', '', 'a@".SYNTAX-ERROR."'],
            'host with two dots running' => ['a', 'x..y', '', 'a@"x..y"'],
            'dotted mailbox is left alone' => ['a.b', 'h', '', 'a.b@h'],
            'space in the host' => ['a', 'h b', '', 'a@"h b"'],
            'space in the mailbox' => ['a b', 'h', '', '"a b"@h'],
            // The empty string is written as a pair of quotes rather than as
            // nothing at all, which would read as a different address.
            'empty mailbox' => ['', 'h', '', '""@h'],
            'empty host' => ['a', '', '', 'a@""'],
            // "A null host (HIGHLY discouraged!)": c-client writes the
            // mailbox and stops.
            'host that is a route' => ['a', '@x', '', 'a'],
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

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function writtenAddressFields(): array
    {
        return [
            // Well formed, and the two agree — which is why copying the
            // header text passed for so long.
            'plain' => ['a@b.com', 'a@b.com'],
            'two addresses' => ['a@b.com, c@d.com', 'a@b.com, c@d.com'],
            // From here on the header text is not the answer. The default
            // host is in the written form and not in what was read.
            'no host' => ['a', 'a@UNKNOWN'],
            // The quoting is c-client's, not the sender's.
            'quoted personal' => ['"A B" <a@b.com>', 'A B <a@b.com>'],
            // A name written as a comment moves in front of the address.
            'personal in a comment' => ['a@b.com (X)', 'X <a@b.com>'],
            // A name that was there but empty is no name at all.
            'empty personal' => ['"" <a@b.com>', 'a@b.com'],
            // The route is dropped: c-client's A-D-L output is behind an #if
            // that php_imap.c's build leaves off.
            'source route' => ['<@r.com:a@b.com>', 'a@b.com'],
            // A group reads back with the spacing c-client writes, not the
            // spacing it was given.
            'empty group' => ['Undisclosed:;', 'Undisclosed: ;'],
            'group with members' => ['G: a@b.com, c@d.com;', 'G: a@b.com, c@d.com;'],
            // Nothing an address parser can use means no property at all,
            // not an empty one: php_imap.c guards on the parsed list.
            'nothing to parse' => ['', null],
        ];
    }

    /**
     * The "*address" strings are written out of the parsed list by
     * rfc822_output_address_list(), not copied from the header they were
     * read from.
     */
    #[DataProvider('writtenAddressFields')]
    public function test_the_address_string_is_written_not_copied(string $header, ?string $expected): void
    {
        $parsed = @imap_rfc822_parse_headers("From: {$header}\r\n\r\n");

        $this->assertSame($expected, $parsed->fromaddress ?? null);

        if ($expected === null) {
            $this->assertObjectNotHasProperty('from', $parsed);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function whitespaceBeforeAContinuationWord(): array
    {
        return [
            // Between two words the whitespace is folding and goes.
            'space between two words' => ['=?UTF-8?B?YQ==?= =?UTF-8?B?Yg==?=', 'ab'],
            'fold between two words' => ["=?UTF-8?B?YQ==?=\r\n =?UTF-8?B?Yg==?=", 'ab'],
            // What decides it is a sniff for "=?" with MINENCWORD bytes
            // behind it, and nothing shorter counts — so the space in front
            // of a "=?" that ends the header stays where it is.
            'space before something too short' => ['=?UTF-8?B?YQ==?= =?', 'a =?'],
            'fold before something too short' => ["=?UTF-8?B?YQ==?=\r\n =?", "a\r\n =?"],
        ];
    }

    /**
     * The whitespace between two encoded words belongs to neither, and the
     * whitespace before anything else belongs to the text.
     */
    #[DataProvider('whitespaceBeforeAContinuationWord')]
    public function test_whitespace_before_a_continuation_word(string $input, string $expected): void
    {
        $this->assertSame($expected, imap_utf8($input));
    }

    /**
     * A charset nobody has is not an error: c-client looks the name up in
     * its own table and, finding nothing, hands the bytes back. Nothing is
     * raised on the way — not even something an error handler that ignores
     * "@" could see.
     */
    public function test_an_unknown_charset_is_silent(): void
    {
        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            $decoded = imap_utf8('=?NOSUCHCHARSET?Q?abc?= tail');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('abc tail', $decoded);
        $this->assertSame([], $raised);
    }

    /**
     * Return-Path is not one of the headers rfc822_parse_msg_full() knows:
     * its dispatch on "R" reads Reply-To and References and stops there, so
     * env->return_path stays NIL no matter what the header says. The property
     * exists in the extension, but only imap_mail_compose() ever fills the
     * field behind it.
     */
    public function test_a_return_path_header_sets_no_property(): void
    {
        $properties = array_keys(get_object_vars(imap_rfc822_parse_headers(
            "Return-Path: <bounce@x.com>\r\nFrom: a@b.com\r\nSubject: Ciao\r\n\r\n"
        )));

        $this->assertNotContains('return_path', $properties);
        $this->assertNotContains('return_pathaddress', $properties);
    }

    public function test_qprint_reports_a_bad_sequence_without_refusing_the_text(): void
    {
        $this->assertSame('a=ZZb', imap_qprint('a=ZZb'));
        $this->assertSame('Invalid quoted-printable sequence: =ZZb', imap_last_error());
    }

    /**
     * @return array<string, array{string, string, string|false}>
     */
    public static function quotedPrintablePayloads(): array
    {
        return [
            'hex pair' => ['=41', 'A', false],
            // A "=" with nothing behind it is a soft break at the end of a
            // string that was cut, and goes quietly.
            'trailing equals' => ['a=', 'a', false],
            'equals alone' => ['=', '', false],
            'soft line break' => ["a=\r\nb", 'ab', false],
            // Neither refused nor decoded: what could not be a hex pair
            // stands as the text it is, and the decode carries on past it.
            'two equals' => ['==', '==', 'Invalid quoted-printable sequence: =='],
            'one hex digit and a letter' => ['=Z', '=Z', 'Invalid quoted-printable sequence: =Z'],
            // The second character is eaten once the first has proved itself
            // a hex digit, which is why the report starts at the "o" and the
            // "o" is gone from the text.
            'hex digit then a letter' => ['x=Doe', 'x=De', 'Invalid quoted-printable sequence: =oe'],
            'letter then hex digits' => ['a=ZZb', 'a=ZZb', 'Invalid quoted-printable sequence: =ZZb'],
            // Spaces before a line break were put there by a mail system,
            // not by anyone, and c-client drops them.
            'trailing spaces before a break' => ["a  \r\nb", "a\r\nb", false],
            'quoted space survives' => ['x =20y', 'x  y', false],
        ];
    }

    #[DataProvider('quotedPrintablePayloads')]
    public function test_qprint_decodes_the_way_rfc822_qprint_does(string $input, string $expected, string|false $error): void
    {
        imap_errors();

        $this->assertSame($expected, imap_qprint($input));
        $this->assertSame($error === false ? [] : [$error], imap_errors() ?: []);
    }

    /**
     * Only the first fault in a string is reported, and only the eighty
     * characters after the "=" of it.
     */
    public function test_qprint_reports_the_first_fault_only_and_at_eighty_characters(): void
    {
        imap_errors();

        imap_qprint('=Z'.str_repeat('x', 100).'=Y');

        $this->assertSame(
            ['Invalid quoted-printable sequence: =Z'.str_repeat('x', 79)],
            imap_errors(),
        );
    }

    /**
     * c-client has no separate close timeout, so it never reports one.
     */
    public function test_close_timeout_is_zero(): void
    {
        $this->assertSame(0, imap_timeout(IMAP_CLOSETIMEOUT));
    }
}
