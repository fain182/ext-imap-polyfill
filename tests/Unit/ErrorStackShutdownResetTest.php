<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Support\ErrorStack;
use ImapPolyfill\Tests\ResetsErrorStack;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Exercises ErrorStack's self-registering shutdown reset directly, since
 * register_shutdown_function callbacks only fire at actual process exit —
 * not observable through a normal PHPUnit run, let alone through the real
 * extension (a worker-mode SAPI leak has no imap_* observable to compare
 * against, so this can't be a parity test either).
 */
class ErrorStackShutdownResetTest extends TestCase
{
    use ResetsErrorStack;

    public function test_pushing_registers_a_shutdown_reset_exactly_once(): void
    {
        $reflection = new ReflectionClass(ErrorStack::class);
        $registered = $reflection->getProperty('shutdownRegistered');

        $this->assertFalse($registered->getValue());

        ErrorStack::push('first error');
        $this->assertTrue($registered->getValue());

        ErrorStack::push('second error');
        $this->assertTrue($registered->getValue());
    }

    public function test_simulated_shutdown_clears_state_and_rearms_on_next_push(): void
    {
        $reflection = new ReflectionClass(ErrorStack::class);
        $registered = $reflection->getProperty('shutdownRegistered');
        $resetForNextRequest = $reflection->getMethod('resetForNextRequest');

        ErrorStack::push('leaked error');
        ErrorStack::pushAlert('leaked alert');

        // Simulates what happens at the end of a request/worker tick,
        // without actually terminating the PHPUnit process.
        $resetForNextRequest->invoke(null);

        $this->assertFalse($registered->getValue());
        $this->assertFalse(imap_errors());
        $this->assertFalse(imap_alerts());
        $this->assertFalse(imap_last_error());

        ErrorStack::push('next request error');
        $this->assertTrue($registered->getValue());
    }
}
