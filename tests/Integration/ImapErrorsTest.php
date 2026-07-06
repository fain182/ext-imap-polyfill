<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

use Fain182\ImapPolyfill\Tests\ResetsErrorStack;

class ImapErrorsTest extends GreenmailTestCase
{
    use ResetsErrorStack;

    public function test_returns_false_when_no_errors_occurred(): void
    {
        if (extension_loaded('imap')) {
            // Real ext-imap keeps a single global error stack for the whole
            // PHP process with no userland reset hook; once any other test
            // in this run has triggered a real IMAP error, this can no
            // longer observe a pristine state. Our own ErrorStack is reset
            // per-test via ResetsErrorStack, so this only applies when
            // running against the genuine extension (the "parity" job).
            $this->markTestSkipped('ext-imap has no way to reset its global error stack between tests.');
        }

        $this->assertFalse(imap_errors());
    }

    public function test_returns_and_drains_accumulated_errors(): void
    {
        imap_open(self::mailboxSpec(), self::USER, 'wrong-password');

        $errors = imap_errors();

        // c-client may retry a failed login internally, pushing more than one
        // error for a single imap_open() call; this polyfill does not retry.
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);
        $this->assertFalse(imap_errors());
    }
}
