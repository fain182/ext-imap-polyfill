<?php

namespace ImapPolyfill\Tests;

/**
 * Collects the diagnostics a call emits, for the paths where ext-imap warns
 * instead of recording an error.
 *
 * The text is the assertion, never the level: ext-imap emits E_WARNING from
 * C, and trigger_error() cannot, so the polyfill emits E_USER_WARNING and the
 * same test has to pass under `make parity`.
 */
trait CapturesWarnings
{
    /**
     * @return array{0: mixed, 1: string[]} what the call returned, and what it said
     */
    protected function capturingWarnings(callable $call): array
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
