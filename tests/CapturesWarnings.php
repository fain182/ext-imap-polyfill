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

    /**
     * An imap_open() that fails answers false and raises the one warning
     * php_imap.c raises whatever the reason, naming the spec as given.
     */
    protected function assertOpenFails(string $spec, string $user, string $password, int $flags = 0, int $retries = 0): void
    {
        $this->assertSame(
            [false, ["imap_open(): Couldn't open stream {$spec}"]],
            $this->capturingWarnings(static fn () => imap_open($spec, $user, $password, $flags, $retries)),
        );
    }
}
