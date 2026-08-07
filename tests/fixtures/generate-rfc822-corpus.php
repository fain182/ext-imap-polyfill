<?php

/**
 * Regenerates tests/fixtures/rfc822-corpus.php by reading the answers off
 * the genuine ext-imap.
 *
 *     make parity-build
 *     podman run --rm -v "$PWD":/app:Z ext-imap-polyfill-parity \
 *         php tests/fixtures/generate-rfc822-corpus.php
 *
 * It refuses to run without the real extension loaded: a corpus generated
 * from the polyfill would record this project's own bugs as the standard
 * and the test built on it would pass forever.
 *
 * The inputs are the pathological ones — comments, escapes, folding,
 * groups, routes — where a regex-shaped parser and a character scanner
 * part company. Add to $addressLists/$mimeHeaders and rerun; never edit
 * the generated file by hand.
 */
if (!extension_loaded('imap')) {
    fwrite(STDERR, "Refusing to generate: ext-imap is not loaded, so these would be the polyfill's own answers.\n");

    exit(1);
}

$addressLists = [
    // Plain shapes, as the baseline the odd ones are read against.
    'bare address' => 'joe@example.com',
    'angle address' => '<joe@example.com>',
    'name and angle address' => 'Joe Doe <joe@example.com>',
    'name touching the bracket' => 'Joe<joe@example.com>',
    'two addresses' => 'a@b.com, c@d.com',
    'no host at all' => 'joe',

    // Comments. They nest, which is the whole reason a regex cannot do this.
    'comment after the name' => 'Joe (the boss) <joe@example.com>',
    'nested comment' => 'Joe (the (big) boss) <joe@example.com>',
    'comment before the name' => '(hi) Joe <joe@example.com>',
    'comment only' => '(just a comment) joe@example.com',
    'comment between two addresses' => 'a@b.com, (hi) c@d.com',
    'comment after the address' => 'joe@example.com (Joe Doe)',
    'comment inside angle brackets' => '<joe@(the host)example.com>',
    'unterminated comment' => 'Joe (never closed <joe@example.com>',
    'escaped paren inside a comment' => 'Joe (not \) yet) <joe@example.com>',
    'empty comment' => 'Joe () <joe@example.com>',
    // A comment between two words of the name survives, where one before
    // or after the name does not: c-client copies the source span running
    // from the first real token to the last, comments and all.
    'comment between two words' => 'Joe (x) Doe <a@b.com>',
    'comment gluing two words' => 'Joe(x)Doe <a@b.com>',
    'leading comment then quoted name' => '(x) "Joe Doe" <a@b.com>',
    'comment inside a quoted name' => '"Joe (x) Doe" <a@b.com>',
    'comment holding a quote' => 'Joe ("x) <a@b.com>',
    'comment in the local part' => '<(x)joe@example.com>',
    'comment after an angle address' => '<a@b.com> (Joe)',
    'comment after a named address' => 'Joe <a@b.com> (Other)',
    'comment after the address, nested' => 'joe@example.com (Joe (the boss) Doe)',
    'two quoted words' => '"a" "b" <x@y.com>',
    'quoted word then atom' => '"a" b <x@y.com>',

    // Quoted strings.
    'quoted name' => '"Simple Name" <a@b.com>',
    'quoted name with a colon' => '"This one: is right" <ding@dong.com>',
    'quoted name with an escaped quote' => '"This one: is \"right\"" <ding@dong.com>',
    'quoted name with an escaped backslash' => '"Back \\\\ slash" <a@b.com>',
    'quoted name hiding a comma' => '"Doe, John" <a@b.com>',
    'quoted name hiding a comment' => '"Joe (not a comment)" <a@b.com>',
    'quoted name hiding an angle bracket' => '"Joe <not@an.address>" <a@b.com>',
    'quoted name written empty' => '"" <a@b.com>',
    'quote that never closes' => '"unterminated <a@b.com>',
    'quoted local part' => '"joe doe"@example.com',

    // Groups.
    'empty group' => 'undisclosed-recipients:;',
    'group with members' => 'Friends: alice@example.com, bob@example.com;',
    'group left unterminated' => 'Friends: alice@example.com',
    'address after a closed group' => 'Friends: a@b.com; c@d.com',

    // Routes, folding, encoded words.
    'route address' => '@relay.example.com:user@example.com',
    'folded before the address' => "Joe\r\n <joe@example.com>",
    'folded between addresses' => "a@b.com,\r\n c@d.com",
    'encoded word as the name' => '=?UTF-8?B?Sm9l?= <joe@example.com>',
    'encoded word touching the bracket' => '=?X-IAS-German?B?bXlHb3Y=?=<info@bla.bla>',
    'encoded word inside quotes' => '"=?UTF-8?B?Sm9l?=" <joe@example.com>',

    // Degenerate input.
    'empty string' => '',
    'only whitespace' => '   ',
    'only a comma' => ',',
    'trailing comma' => 'a@b.com,',
    'two at signs' => 'a@b@c.com',
];

$mimeHeaders = [
    'plain text' => 'plain text',
    'one base64 word' => '=?UTF-8?B?Y2lhbw==?=',
    'one quoted printable word' => '=?ISO-8859-1?Q?caf=E9?=',
    'underscore is a space in Q' => '=?ISO-8859-1?Q?Kilgore_Trout?=',
    'word between plain text' => 'a =?UTF-8?B?Yg==?= c',
    'adjacent words, one space' => '=?UTF-8?B?YQ==?= =?UTF-8?B?Yg==?=',
    'adjacent words, several spaces' => '=?UTF-8?B?YQ==?=   =?UTF-8?B?Yg==?=',
    'adjacent words, a tab' => "=?UTF-8?B?YQ==?=\t=?UTF-8?B?Yg==?=",
    'adjacent words, folded' => "=?UTF-8?B?YQ==?=\r\n =?UTF-8?B?Yg==?=",
    'adjacent words, nothing between' => '=?UTF-8?B?YQ==?==?UTF-8?B?Yg==?=',
    'adjacent words, different charsets' => '=?UTF-8?B?YQ==?= =?ISO-8859-1?Q?b?=',
    'leading space' => ' =?UTF-8?B?YQ==?=',
    'trailing space' => '=?UTF-8?B?YQ==?= ',
    'unknown encoding letter' => '=?UTF-8?X?Y2lhbw==?=',
    'undecodable base64' => '=?UTF-8?B?bad!!base64?=',
    'empty payload' => '=?UTF-8?B??=',
    'unterminated word' => '=?UTF-8?B?Y2lhbw==',
    'charset with a space' => '=?UTF 8?B?Y2lhbw==?=',
];

$addresses = [];
foreach ($addressLists as $label => $input) {
    $parsed = @imap_rfc822_parse_adrlist($input, 'default.host');
    $addresses[$label] = [
        'input' => $input,
        'expected' => array_map(static fn (\stdClass $a): array => get_object_vars($a), $parsed),
    ];
}

$mime = [];
foreach ($mimeHeaders as $label => $input) {
    $decoded = @imap_mime_header_decode($input);
    $mime[$label] = [
        'input' => $input,
        'expected' => $decoded === false
            ? false
            : array_map(static fn (\stdClass $p): array => [$p->charset, $p->text], $decoded),
    ];
}

/**
 * var_export() would write decoded ISO-8859-1 payloads as raw bytes,
 * leaving a checked-in file that is not valid UTF-8 and that no editor or
 * diff renders honestly. Everything outside printable ASCII is escaped
 * instead, so the fixture stays readable as the specification it is.
 */
$render = static function (mixed $value, int $depth = 0) use (&$render): string {
    $pad = str_repeat('    ', $depth + 1);

    if (is_array($value)) {
        if ($value === []) {
            return '[]';
        }

        $lines = [];
        foreach ($value as $key => $item) {
            $lines[] = $pad.$render($key, $depth + 1).' => '.$render($item, $depth + 1).',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }

    if (is_string($value)) {
        $escaped = preg_replace_callback(
            '/[^\x20-\x7E]|["$\\\\]/',
            static fn (array $m): string => sprintf('\x%02X', ord($m[0])),
            $value
        );

        return '"'.$escaped.'"';
    }

    return var_export($value, true);
};

$export = $render(['adrlist' => $addresses, 'mime_header_decode' => $mime]);
$header = <<<'PHP'
<?php

/**
 * GENERATED FILE — do not edit by hand.
 *
 * The answers the genuine ext-imap gives for the corpus in
 * generate-rfc822-corpus.php, which is also how to regenerate it.
 * Rfc822CorpusTest asserts both engines against it.
 */

return
PHP;

file_put_contents(__DIR__.'/rfc822-corpus.php', $header.' '.$export.";\n");

printf("Wrote %d address lists and %d headers.\n", count($addresses), count($mime));
