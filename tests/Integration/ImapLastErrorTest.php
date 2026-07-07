<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\ResetsErrorStack;

class ImapLastErrorTest extends GreenmailTestCase
{
    use ResetsErrorStack;

    public function test_returns_false_when_no_error_has_occurred(): void
    {
        if (extension_loaded('imap')) {
            // See ImapErrorsTest::test_returns_false_when_no_errors_occurred.
            $this->markTestSkipped('ext-imap has no way to reset its global error stack between tests.');
        }

        $this->assertFalse(imap_last_error());
    }

    public function test_returns_the_message_of_the_last_error(): void
    {
        imap_open(self::mailboxSpec(), self::USER, 'wrong-password');

        $this->assertIsString(imap_last_error());
    }

    public function test_is_cleared_when_imap_errors_drains_the_stack(): void
    {
        imap_open(self::mailboxSpec(), self::USER, 'wrong-password');
        $this->assertIsString(imap_last_error());

        imap_errors();

        // imap_last_error() reads the same stack imap_errors() frees, so
        // after a drain it reports false, not the stale last message.
        $this->assertFalse(imap_last_error());
    }
}
