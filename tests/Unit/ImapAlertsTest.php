<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Support\ErrorStack;
use ImapPolyfill\Tests\ResetsErrorStack;
use PHPUnit\Framework\TestCase;

class ImapAlertsTest extends TestCase
{
    use ResetsErrorStack;

    public function test_returns_false_when_no_alerts_occurred(): void
    {
        $this->assertFalse(imap_alerts());
    }

    public function test_returns_and_drains_accumulated_alerts(): void
    {
        ErrorStack::pushAlert('IMAP server will shut down for maintenance in 10 minutes');

        $alerts = imap_alerts();

        $this->assertSame(['IMAP server will shut down for maintenance in 10 minutes'], $alerts);
        $this->assertFalse(imap_alerts());
    }
}
