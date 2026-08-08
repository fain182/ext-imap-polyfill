<?php

namespace ImapPolyfill\Tests\Support;

/**
 * The conversions, one charset at a time.
 *
 * Non-ASCII input is the most productive thing to feed this library:
 * several divergences found one at a time were tripped by an accented
 * mailbox name or an encoded word rather than by the charset code itself.
 * These are the calls where the bytes are the whole point.
 *
 * Shared by generate-charset-corpus.php and CharsetCorpusTest so a
 * recorded answer and a replayed one come from the same call — the fixture
 * records labels, not call bodies, so nothing else would notice the two
 * drifting apart.
 */
final class CharsetCalls
{
    /**
     * @return array<string, callable(): mixed>
     */
    public static function all(): array
    {
        $latin1 = static fn (string $utf8): string => (string) iconv('UTF-8', 'ISO-8859-1', $utf8);
        $cp1251 = static fn (string $utf8): string => (string) iconv('UTF-8', 'Windows-1251', $utf8);

        /** Text that costs nothing in ASCII and everything outside it. */
        $texts = [
            'ascii' => 'plain',
            'accented latin' => 'caffè',
            'mixed latin' => 'Zoë Doe',
            'cyrillic' => 'Привет',
            'cjk' => '你好',
            'symbol' => '€uro',
            'combining already' => "cafe\u{0301}",
        ];

        $calls = [];

        foreach ($texts as $label => $text) {
            // The mailbox-name conversions, both directions and the round trip.
            $calls["imap_utf7_encode({$label})"] = static fn () => imap_utf7_encode($text);
            $calls["imap_utf7_decode(encoded {$label})"] = static fn () => imap_utf7_decode(imap_utf7_encode($text));
            $calls["imap_utf7_encode(latin1 {$label})"] = static fn () => imap_utf7_encode($latin1($text));

            // Header decoding, per charset the encoded word may name.
            $calls["imap_utf8(UTF-8 word, {$label})"] = static fn () => imap_utf8('=?UTF-8?B?'.base64_encode($text).'?=');
            $calls["imap_utf8(ISO-8859-1 word, {$label})"] = static fn () => imap_utf8('=?ISO-8859-1?B?'.base64_encode($latin1($text)).'?=');
            $calls["imap_utf8(Windows-1251 word, {$label})"] = static fn () => imap_utf8('=?Windows-1251?B?'.base64_encode($cp1251($text)).'?=');
            $calls["imap_utf8(Q encoded, {$label})"] = static fn () => imap_utf8('=?UTF-8?Q?'.quoted_printable_encode($text).'?=');
            $calls["imap_utf8(raw 8-bit, {$label})"] = static fn () => imap_utf8($text);

            // The transfer encodings, on bytes that are not ASCII.
            $calls["imap_8bit({$label})"] = static fn () => imap_8bit($text);
            $calls["imap_qprint(8bit {$label})"] = static fn () => imap_qprint(imap_8bit($text));
            $calls["imap_binary({$label})"] = static fn () => imap_binary($text);
            $calls["imap_base64(binary {$label})"] = static fn () => imap_base64(imap_binary($text));
        }

        // Shapes that have nothing to do with the alphabet and everything
        // to do with the encoding being wrong.
        $calls['imap_utf8(charset nobody has)'] = static fn () => imap_utf8('=?X-IAS-German?B?bXlHb3Y=?=');
        $calls['imap_utf8(unterminated word)'] = static fn () => imap_utf8('=?UTF-8?B?Y2FmZQ==');
        $calls['imap_utf8(empty payload)'] = static fn () => imap_utf8('=?UTF-8?B??=');
        $calls['imap_utf7_decode(not utf7)'] = static fn () => imap_utf7_decode('caff&w6g');
        $calls['imap_utf7_decode(bare ampersand)'] = static fn () => imap_utf7_decode('a&b');
        $calls['imap_utf7_decode(escaped ampersand)'] = static fn () => imap_utf7_decode('a&-b');
        $calls['imap_utf7_encode(empty)'] = static fn () => imap_utf7_encode('');
        $calls['imap_base64(not base64)'] = static fn () => imap_base64('not!!base64');
        $calls['imap_qprint(broken escape)'] = static fn () => imap_qprint('caf=ZZ');

        return $calls;
    }

    /**
     * The calls whose divergence is deliberate and written down in the
     * README's table rather than fixed. c-client hands back decomposed
     * UTF-8 ("cafe" + U+0301) for the Latin letters that have a composed
     * form, and matching that without ext-intl would mean carrying a
     * Unicode decomposition table.
     *
     * Named one by one rather than matched by a pattern over the labels:
     * only these actually differ, and a rule over names would quietly
     * cover a neighbour that does not.
     *
     * @return list<string>
     */
    public static function documentedDivergences(): array
    {
        return [
            'imap_utf8(UTF-8 word, accented latin)',
            'imap_utf8(ISO-8859-1 word, accented latin)',
            'imap_utf8(Q encoded, accented latin)',
            'imap_utf8(UTF-8 word, mixed latin)',
            'imap_utf8(ISO-8859-1 word, mixed latin)',
        ];
    }

    /**
     * What a call answered, in the shape the fixture records.
     *
     * @return array{returns: string, value: mixed}
     */
    public static function outcome(callable $call): array
    {
        $result = @$call();

        return ['returns' => get_debug_type($result), 'value' => $result];
    }
}
