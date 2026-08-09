<?php

/**
 * Reads the two engines' answers for the same corpus and reports where they
 * differ.
 *
 *     php tests/fuzz/compare.php corpus answers.polyfill answers.real
 *
 * Exits non-zero when anything is left after the documented divergences are
 * set aside, and prints one shortest input per (call, kind of difference) —
 * a thousand mutations of the same seed hit the same bug a thousand times,
 * and the first line of a report nobody reads is the one that says so.
 */

$corpusPath = $argv[1] ?? null;
$polyfillPath = $argv[2] ?? null;
$realPath = $argv[3] ?? null;

foreach ([$corpusPath, $polyfillPath, $realPath] as $path) {
    if ($path === null || !is_file($path)) {
        fwrite(STDERR, "usage: compare.php <corpus> <answers.polyfill> <answers.real>\n");

        exit(2);
    }
}

$corpus = array_map(hex2bin(...), file($corpusPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

/**
 * The one difference this project has decided not to answer: c-client
 * canonicalises to decomposed UTF-8 (php_imap.c passes U8T_DECOMPOSE),
 * where this package returns the text as the charset wrote it. Normalizing
 * the polyfill's side is how the fuzzer tells that difference from a real
 * one, rather than drowning in it. See the imap_utf8 row of the README.
 */
function isTheDocumentedDecomposition(string $label, string $polyfill, string $real): bool
{
    if (($label !== 'utf8' && $label !== 'mime_header_decode') || !class_exists(\Normalizer::class)) {
        return false;
    }

    // Both sides are hex-encoded answers; decompose every string in the
    // polyfill's and see whether that is what the extension said.
    $decomposed = preg_replace_callback(
        '/hex:([0-9a-f]*)/',
        static function (array $match): string {
            $text = hex2bin($match[1]);
            $normalized = @\Normalizer::normalize($text, \Normalizer::FORM_D);

            return 'hex:'.bin2hex(is_string($normalized) ? $normalized : $text);
        },
        $polyfill,
    );

    return $decomposed === $real;
}

/**
 * How two answers differ, coarsely enough that a thousand mutations of the
 * same bug land in one bucket.
 *
 * Every input is its own structure, so keying on the answers themselves
 * gives one "finding" per input and buries the four or five that are real.
 * What survives here is which of the three parts disagreed — the value, the
 * error stack, the warnings — and whether the values disagree in shape or
 * only in the bytes they carry.
 */
function difference(string $polyfill, string $real): string
{
    $parts = [];
    $left = explode("\t", $polyfill);
    $right = explode("\t", $real);

    foreach (['value', 'errors', 'warnings'] as $position => $name) {
        if (($left[$position] ?? '') === ($right[$position] ?? '')) {
            continue;
        }

        $skeleton = static fn (string $answer): string => preg_replace(
            ['/hex:[0-9a-f]*/', '/\d+/'],
            ['S', 'N'],
            $answer,
        );

        $parts[] = $name.($skeleton($left[$position] ?? '') === $skeleton($right[$position] ?? '')
            ? '(bytes)'
            : '(shape)');
    }

    return implode('+', $parts);
}

/**
 * An answer with its hex put back into quoted text. Nobody reads two
 * hundred bytes of hex, and a report nobody reads finds nothing.
 */
function readable(string $answer): string
{
    return preg_replace_callback(
        '/hex:([0-9a-f]*)/',
        static fn (array $match): string => var_export(hex2bin($match[1]), true),
        $answer,
    );
}

$polyfill = fopen($polyfillPath, 'r');
$real = fopen($realPath, 'r');

$differences = [];
$known = 0;
$compared = 0;

while (($left = fgets($polyfill)) !== false) {
    $right = fgets($real);

    if ($right === false) {
        fwrite(STDERR, "The two answer files are not the same length: one engine stopped early.\n");

        exit(2);
    }

    $compared++;

    if ($left === $right) {
        continue;
    }

    [$index, $label, $polyfillAnswer] = explode("\t", rtrim($left, "\n"), 3);
    [, , $realAnswer] = explode("\t", rtrim($right, "\n"), 3);
    $index = (int) $index;

    if (isTheDocumentedDecomposition($label, $left, $right)) {
        $known++;

        continue;
    }

    $shape = $label.' '.difference($polyfillAnswer, $realAnswer);
    $input = $corpus[$index] ?? '';

    if (!isset($differences[$shape]) || strlen($input) < strlen($differences[$shape]['input'])) {
        $differences[$shape] = [
            'input' => $input,
            'label' => $label,
            'polyfill' => $polyfillAnswer,
            'real' => $realAnswer,
            'count' => 0,
        ];
    }

    $differences[$shape]['count']++;
}

printf("%d answers compared, %d documented divergences set aside.\n", $compared, $known);

if ($differences === []) {
    echo "No divergence.\n";

    exit(0);
}

printf("%d distinct divergences:\n\n", count($differences));

foreach ($differences as $difference) {
    printf("[%s] hit %d times, shortest input:\n", $difference['label'], $difference['count']);
    printf("  input    %s\n", var_export($difference['input'], true));
    printf("  polyfill %s\n", readable($difference['polyfill']));
    printf("  real     %s\n\n", readable($difference['real']));
}

exit(1);
