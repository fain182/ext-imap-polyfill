<?php

/**
 * The answer format tests/fuzz/compare.php reads, shared by the two scripts
 * that write it (tests/fuzz/evaluate.php and tests/realmail/crawl.php):
 * a change here is a change to both, or compare.php reads one of them wrong.
 */

/**
 * The answer as a diffable string: types kept, strings hexed because most
 * of these are not valid UTF-8 and some are not meant to be, keys kept in
 * the order the engine wrote them — the order is part of the answer. A
 * string longer than an attachment worth reading is its length and hash,
 * or a mailbox of PDFs makes answer files nobody can diff.
 */
function encode(mixed $value): string
{
    if (is_string($value)) {
        return strlen($value) > 65536
            ? 'big:'.strlen($value).':'.sha1($value)
            : 'hex:'.bin2hex($value);
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

/**
 * Runs one call and prints its line: the value or what it threw, then the
 * error stack and the warnings it left.
 */
function answer(int $index, string $label, callable $call): void
{
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
        $answer = encode($call());
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
