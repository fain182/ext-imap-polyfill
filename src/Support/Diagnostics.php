<?php

namespace ImapPolyfill\Support;

/**
 * What a PHP builtin says through the error system while the polyfill calls
 * it, kept from the caller's own error handler.
 *
 * "@" only lowers error_reporting() for the call: a handler that does not
 * honour it — most of the ones a framework installs — still receives the
 * warning, which is one the real extension never raises, about a failure
 * the polyfill reports in its own words.
 */
final class Diagnostics
{
    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    public static function discard(callable $call): mixed
    {
        return self::collect($call)[0];
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return array{0: T, 1: list<string>} what the call returned, and the messages it raised
     */
    public static function collect(callable $call): array
    {
        $messages = [];
        set_error_handler(static function (int $errno, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        });

        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        return [$result, $messages];
    }
}
