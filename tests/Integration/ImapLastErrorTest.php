<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

use Fain182\ImapPolyfill\Tests\ResetsErrorStack;

class ImapLastErrorTest extends GreenmailTestCase
{
    use ResetsErrorStack;

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
