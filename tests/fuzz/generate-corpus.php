<?php

/**
 * Writes the corpus the two engines are then fed, one input per line,
 * hex-encoded because most of what makes a good input here is not text.
 *
 *     php tests/fuzz/generate-corpus.php <seed> <count> > corpus
 *
 * The corpus is a file rather than a generator each side runs because the
 * two sides are different PHP versions in different containers: the only
 * way to be sure they saw the same bytes is to hand them the same bytes.
 * Same seed, same corpus, so a run that found something can be repeated.
 *
 * Random bytes are close to worthless against these functions — they never
 * survive the first "=?" or the first angle bracket. Everything here is a
 * *seeded* mutation instead: real headers, damaged in the ways headers are
 * damaged in the wild, which is where every divergence found by hand so far
 * has come from.
 */

$seed = (int) ($argv[1] ?? 1);
$count = (int) ($argv[2] ?? 2000);

mt_srand($seed);

/** The characters RFC 822 gives a meaning to, which are the ones worth moving. */
const SPECIALS = ['"', '<', '>', '(', ')', '@', ',', ';', ':', '\\', '.', '[', ']', ' ', "\t", "\r\n\t", '?', '='];

/** @return list<string> */
function seeds(): array
{
    $corpus = require __DIR__.'/../fixtures/rfc822-corpus.php';

    $seeds = [];

    // The address grammar's own recorded inputs: real syntax, already
    // interesting, and each one a place the parser branches.
    foreach ($corpus as $group) {
        foreach ($group as $case) {
            if (is_string($case['input'] ?? null)) {
                $seeds[] = $case['input'];
            }
        }
    }

    return array_values(array_unique(array_merge($seeds, [
        'Joe Doe <joe@example.com>',
        '"Undisclosed Recipients" <>',
        'undisclosed-recipients:;',
        'a@b.com, c@d.com',
        '<@route.example.com:foo@example.ac.uk>',
        'joe@example.com (Joe Doe)',
        '=?UTF-8?B?Y2FmZsOo?=',
        '=?UTF-8?Q?Kilgore_Trout?=',
        '=?ISO-8859-1?Q?a=E8b?=',
        '=?UTF-8?B?YQ==?= =?UTF-8?B?Yg==?=',
        '=?X-IAS-German?B?bXlHb3Y=?=<info@bla.bla>',
        "Subject: =?UTF-8?B?Y2FmZsOo?=\r\n\tsecond line",
        "From: a@b.com\r\nTo: c@d.com\r\nSubject: hi\r\n\r\n",
        "Return-Path: <>\r\nFrom: a@b.com\r\n\r\n",
        "Date: Mon, 1 Jan 2024 00:00:00 +0000\r\nFrom: \"A B\" <a@b.com>\r\n\r\n",
    ])));
}

/**
 * One damaged copy of $input. The mutations are the faults these parsers
 * are written to survive — a bracket that never closes, padding in the
 * wrong place, a raw 8-bit byte where a charset was promised — rather than
 * arbitrary edits, which is what keeps the hit rate up.
 */
function mutate(string $input, array $seeds): string
{
    $length = strlen($input);

    switch (mt_rand(0, 11)) {
        case 0: // Drop a byte: unbalances whatever it belonged to.
            return $length === 0 ? $input : substr_replace($input, '', mt_rand(0, $length - 1), 1);

        case 1: // Insert a special where it has no business being.
            return substr_replace($input, SPECIALS[array_rand(SPECIALS)], mt_rand(0, $length), 0);

        case 2: // Duplicate a special that is already there.
            $special = SPECIALS[array_rand(SPECIALS)];

            return str_replace($special, $special.$special, $input);

        case 3: // Cut it short, which is how a folded header arrives truncated.
            return $length < 2 ? $input : substr($input, 0, mt_rand(1, $length - 1));

        case 4: // A raw 8-bit byte: legal in no header, present in many.
            return substr_replace($input, chr(mt_rand(0x80, 0xFF)), mt_rand(0, $length), 0);

        case 5: // Wrap it in an encoded word, payload and all.
            return '=?'.['UTF-8', 'ISO-8859-1', 'X-NOPE', 'UTF-8*en'][mt_rand(0, 3)].'?'
                .['B', 'Q', 'X'][mt_rand(0, 2)].'?'.$input.'?=';

        case 6: // Base64 that stops being base64 partway.
            $data = base64_encode($input);

            return '=?UTF-8?B?'.substr($data, 0, max(1, (int) (strlen($data) * mt_rand(1, 9) / 10))).'?=';

        case 7: // Padding, in a position that may or may not allow it.
            return substr_replace($input, str_repeat('=', mt_rand(1, 3)), mt_rand(0, $length), 0);

        case 8: // Two inputs where one was expected.
            $other = $seeds[array_rand($seeds)];

            return $input.[', ', ' ', '; ', ':', ''][mt_rand(0, 4)].$other;

        case 9: // Folding whitespace, in the middle of a token.
            return substr_replace($input, ["\r\n ", "\r\n\t", "\r\n", "\t", '  '][mt_rand(0, 4)], mt_rand(0, $length), 0);

        case 10: // A quote or a bracket opened and never closed.
            return substr_replace($input, ['"', '<', '(', '['][mt_rand(0, 3)], mt_rand(0, $length), 0);

        default: // A backslash escape, including the one that eats the delimiter.
            return substr_replace($input, '\\', mt_rand(0, $length), 0);
    }
}

$seeds = seeds();

// The seeds themselves go in first: a mutation that finds nothing still has
// to be told apart from a seed that was never tried.
foreach ($seeds as $seed) {
    echo bin2hex($seed), "\n";
}

for ($index = 0; $index < $count; $index++) {
    $input = $seeds[array_rand($seeds)];

    foreach (range(0, mt_rand(0, 2)) as $ignored) {
        $input = mutate($input, $seeds);
    }

    // Anything longer than a folded header line is more likely to time the
    // run out than to find anything the shorter cases do not.
    echo bin2hex(substr($input, 0, 400)), "\n";
}
