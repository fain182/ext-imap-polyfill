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

require __DIR__.'/answer.php';

$handle = fopen($corpusPath, 'r');
$index = 0;

while (($line = fgets($handle)) !== false) {
    $line = trim($line);

    if ($line === '') {
        continue;
    }

    $input = hex2bin($line);

    foreach ($calls as $label => $call) {
        answer($index, $label, static fn (): mixed => $call($input));
    }

    $index++;
}

fclose($handle);
