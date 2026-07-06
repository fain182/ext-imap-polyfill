<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class ImapLastErrorTest extends GreenmailTestCase
{
    #[RunInSeparateProcess]
    public function test_returns_false_when_no_error_has_occurred(): void
    {
        $this->assertFalse(imap_last_error());
    }

    public function test_returns_the_message_of_the_last_error(): void
    {
        imap_open(self::mailboxSpec(), self::USER, 'wrong-password');

        $this->assertIsString(imap_last_error());
    }
}
