<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class ImapErrorsTest extends GreenmailTestCase
{
    #[RunInSeparateProcess]
    public function test_returns_false_when_no_errors_occurred(): void
    {
        $this->assertFalse(imap_errors());
    }

    #[RunInSeparateProcess]
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
