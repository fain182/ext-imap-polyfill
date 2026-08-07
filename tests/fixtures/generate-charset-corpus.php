<?php

/**
 * Regenerates tests/fixtures/charset-corpus.php by reading the answers off
 * the genuine ext-imap.
 *
 *     make parity-build
 *     podman run --rm -v "$PWD":/app:Z ext-imap-polyfill-parity \
 *         php tests/fixtures/generate-charset-corpus.php
 *
 * Refuses to run without the real extension, for the same reason the other
 * generators do: a corpus made from the polyfill would record this
 * project's own bugs as the standard.
 *
 * Non-ASCII input is the most productive thing to feed this library —
 * several divergences found one at a time were tripped by an accented
 * mailbox name or an encoded word rather than by the charset code itself.
 * These are the functions where the bytes are the whole point.
 */
require __DIR__.'/../../vendor/autoload.php';

use ImapPolyfill\Tests\Support\FixtureExport;

if (!extension_loaded('imap')) {
    fwrite(STDERR, "Refusing to generate: ext-imap is not loaded, so these would be the polyfill's own answers.\n");

    exit(1);
}

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

// Shapes that have nothing to do with the alphabet and everything to do
// with the encoding being wrong.
$calls['imap_utf8(charset nobody has)'] = static fn () => imap_utf8('=?X-IAS-German?B?bXlHb3Y=?=');
$calls['imap_utf8(unterminated word)'] = static fn () => imap_utf8('=?UTF-8?B?Y2FmZQ==');
$calls['imap_utf8(empty payload)'] = static fn () => imap_utf8('=?UTF-8?B??=');
$calls['imap_utf7_decode(not utf7)'] = static fn () => imap_utf7_decode('caff&w6g');
$calls['imap_utf7_decode(bare ampersand)'] = static fn () => imap_utf7_decode('a&b');
$calls['imap_utf7_decode(escaped ampersand)'] = static fn () => imap_utf7_decode('a&-b');
$calls['imap_utf7_encode(empty)'] = static fn () => imap_utf7_encode('');
$calls['imap_base64(not base64)'] = static fn () => imap_base64('not!!base64');
$calls['imap_qprint(broken escape)'] = static fn () => imap_qprint('caf=ZZ');

/**
 * Calls whose divergence is deliberate and written down in the README's
 * table rather than fixed here. c-client hands back decomposed UTF-8
 * ("cafe" + U+0301) for anything it decodes, and matching that without
 * ext-intl would mean carrying a Unicode decomposition table. Recording
 * either answer would be a lie: the real one would fail forever, ours
 * would enshrine the divergence as the standard.
 */
$documented = [];
foreach (array_keys($calls) as $label) {
    if (str_starts_with($label, 'imap_utf8(') && str_contains($label, 'latin')) {
        $documented[] = $label;
    }
}

$corpus = [];

foreach ($calls as $label => $call) {
    if (in_array($label, $documented, true)) {
        continue;
    }

    $result = @$call();
    $corpus[$label] = is_string($result)
        ? ['returns' => 'string', 'value' => $result]
        : ['returns' => get_debug_type($result), 'value' => $result];
}

$omitted = " *   - ".implode("\n *   - ", $documented);
$header = <<<PHP
<?php

/**
 * GENERATED FILE — do not edit by hand.
 *
 * What the genuine ext-imap answers for each call in
 * generate-charset-corpus.php, which is also how to regenerate it.
 * CharsetCorpusTest asserts both engines against it.
 *
 * Byte values are escaped rather than written raw: several of these
 * answers are not valid UTF-8, and some are not meant to be.
 *
 * Left out, because the difference is the decomposed UTF-8 c-client
 * returns and matching it would mean carrying a Unicode decomposition
 * table; see the imap_utf8 row of the README's table:
 *
{$omitted}
 */

return
PHP;

file_put_contents(__DIR__.'/charset-corpus.php', $header.' '.FixtureExport::render($corpus).";\n");

printf("Wrote %d calls.\n", count($corpus));
