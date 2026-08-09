<?php

/**
 * Runs every call in tests/fuzz/calls.php over every input of a corpus and
 * writes one line per (input, call), whatever answered.
 *
 *     php tests/fuzz/evaluate.php corpus > answers
 *
 * Deliberately knows nothing about which engine it is running under: the
 * same file runs against the polyfill and, in the parity image, against the
 * genuine extension. Comparing the two is compare.php's job.
 *
 * Only functions that need no connection are in here. A fuzzer pointed at a
 * live folder would be testing the server's mood as much as this package.
 */

$corpusPath = $argv[1] ?? null;

if ($corpusPath === null || !is_file($corpusPath)) {
    fwrite(STDERR, "usage: evaluate.php <corpus>\n");

    exit(1);
}

if (!extension_loaded('imap')) {
    require __DIR__.'/../../vendor/autoload.php';
}

$calls = require __DIR__.'/calls.php';

/**
 * The answer as a diffable string: types kept, strings hexed because most
 * of these are not valid UTF-8 and some are not meant to be, keys kept in
 * the order the engine wrote them — the order is part of the answer.
 */
function encode(mixed $value): string
{
    if (is_string($value)) {
        return 'hex:'.bin2hex($value);
    }

    if (is_object($value)) {
        return '{'.encode(get_object_vars($value)).'}';
    }

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $key => $item) {
            $parts[] = (is_int($key) ? (string) $key : 'hex:'.bin2hex($key)).'=>'.encode($item);
        }

        return '['.implode(',', $parts).']';
    }

    return get_debug_type($value).':'.var_export($value, true);
}

$handle = fopen($corpusPath, 'r');
$index = 0;

while (($line = fgets($handle)) !== false) {
    $line = trim($line);

    if ($line === '') {
        continue;
    }

    $input = hex2bin($line);

    foreach ($calls as $label => $call) {
        imap_errors();
        $warnings = [];

        // The severity is not compared, only the text: this package raises
        // E_USER_WARNING where the extension raises E_WARNING, and no
        // userland function can do otherwise.
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $answer = encode($call($input));
        } catch (\Throwable $thrown) {
            $answer = 'threw:'.$thrown::class.':'.encode($thrown->getMessage());
        } finally {
            restore_error_handler();
        }

        printf(
            "%d\t%s\t%s\terrors=%s\twarnings=%s\n",
            $index,
            $label,
            $answer,
            encode(imap_errors() ?: []),
            encode($warnings),
        );
    }

    $index++;
}

fclose($handle);
