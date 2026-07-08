<?php

namespace ImapPolyfill\Tests;

use ImapPolyfill\Support\ErrorStack;
use ReflectionClass;

/**
 * ErrorStack holds process-global static state, matching ext-imap's own
 * global error/alert stack. #[RunInSeparateProcess] was tried as a way to
 * assert against a pristine stack, but proved unreliable in CI (observed
 * failing on GitHub Actions' Ubuntu runners while passing everywhere else
 * tested) — the isolation guarantee it's supposed to provide isn't robust
 * enough to depend on. Resetting the static state directly via reflection
 * before each test is deterministic regardless of environment.
 */
trait ResetsErrorStack
{
    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new ReflectionClass(ErrorStack::class);
        $reflection->getProperty('errors')->setValue(null, []);
        $reflection->getProperty('alerts')->setValue(null, []);
        $reflection->getProperty('lastError')->setValue(null, false);
        $reflection->getProperty('shutdownRegistered')->setValue(null, false);
    }
}
