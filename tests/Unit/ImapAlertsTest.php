<?php

namespace Fain182\ImapPolyfill\Tests\Unit;

use Fain182\ImapPolyfill\ErrorStack;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class ImapAlertsTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_returns_false_when_no_alerts_occurred(): void
    {
        $this->assertFalse(imap_alerts());
    }

    #[RunInSeparateProcess]
    public function test_returns_and_drains_accumulated_alerts(): void
    {
        ErrorStack::pushAlert('IMAP server will shut down for maintenance in 10 minutes');

        $alerts = imap_alerts();

        $this->assertSame(['IMAP server will shut down for maintenance in 10 minutes'], $alerts);
        $this->assertFalse(imap_alerts());
    }
}
