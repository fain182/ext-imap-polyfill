<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The conversions, one charset at a time, against whichever engine is
 * loaded.
 *
 * Non-ASCII input has been the most productive thing to feed this library:
 * an accented mailbox name and an encoded word in a From header tripped
 * divergences that were not about charsets at all. These are the calls
 * where the bytes *are* the point, so they are worth asking in bulk rather
 * than meeting one report at a time.
 *
 * The functions take no connection, so this runs unchanged under
 * `make parity`, and the fixture it reads was written by that run — see
 * generate-charset-corpus.php, which only produces answers with the
 * genuine extension loaded.
 */
final class CharsetCorpusTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function conversions(): array
    {
        $corpus = require __DIR__.'/../fixtures/charset-corpus.php';

        return array_map(
            static fn (string $label): array => [$label, $corpus[$label]],
            array_combine(array_keys($corpus), array_keys($corpus)),
        );
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('conversions')]
    public function test_matches_the_real_extension(string $label, array $expected): void
    {
        $this->assertSame($expected, self::answer($label));
    }

    /**
     * @return array<string, mixed>
     */
    private static function answer(string $label): array
    {
        $result = @self::calls()[$label]();

        return is_string($result)
            ? ['returns' => 'string', 'value' => $result]
            : ['returns' => get_debug_type($result), 'value' => $result];
    }

    /**
     * The same calls the generator makes, in the same order — kept here
     * rather than shared so the fixture records inputs as well as answers,
     * and a call that changes shows up as a changed fixture.
     *
     * @return array<string, callable(): mixed>
     */
    private static function calls(): array
    {
        $latin1 = static fn (string $utf8): string => (string) iconv('UTF-8', 'ISO-8859-1', $utf8);
        $cp1251 = static fn (string $utf8): string => (string) iconv('UTF-8', 'Windows-1251', $utf8);

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
            $calls["imap_utf7_encode({$label})"] = static fn () => imap_utf7_encode($text);
            $calls["imap_utf7_decode(encoded {$label})"] = static fn () => imap_utf7_decode(imap_utf7_encode($text));
            $calls["imap_utf7_encode(latin1 {$label})"] = static fn () => imap_utf7_encode($latin1($text));
            $calls["imap_utf8(UTF-8 word, {$label})"] = static fn () => imap_utf8('=?UTF-8?B?'.base64_encode($text).'?=');
            $calls["imap_utf8(ISO-8859-1 word, {$label})"] = static fn () => imap_utf8('=?ISO-8859-1?B?'.base64_encode($latin1($text)).'?=');
            $calls["imap_utf8(Windows-1251 word, {$label})"] = static fn () => imap_utf8('=?Windows-1251?B?'.base64_encode($cp1251($text)).'?=');
            $calls["imap_utf8(Q encoded, {$label})"] = static fn () => imap_utf8('=?UTF-8?Q?'.quoted_printable_encode($text).'?=');
            $calls["imap_utf8(raw 8-bit, {$label})"] = static fn () => imap_utf8($text);
            $calls["imap_8bit({$label})"] = static fn () => imap_8bit($text);
            $calls["imap_qprint(8bit {$label})"] = static fn () => imap_qprint(imap_8bit($text));
            $calls["imap_binary({$label})"] = static fn () => imap_binary($text);
            $calls["imap_base64(binary {$label})"] = static fn () => imap_base64(imap_binary($text));
        }

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
}
