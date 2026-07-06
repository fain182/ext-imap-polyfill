<?php

namespace Fain182\ImapPolyfill\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ImapTimeoutTest extends TestCase
{
    public function test_returns_default_timeout_when_not_set(): void
    {
        $this->assertSame((int) ini_get('default_socket_timeout'), imap_timeout(IMAP_READTIMEOUT));
    }

    public function test_sets_and_reads_back_a_timeout(): void
    {
        $this->assertTrue(imap_timeout(IMAP_WRITETIMEOUT, 15));
        $this->assertSame(15, imap_timeout(IMAP_WRITETIMEOUT));
    }

    public function test_returns_false_for_unknown_timeout_type(): void
    {
        $this->assertFalse(imap_timeout(99));
    }

    public function test_returns_false_when_setting_an_unknown_timeout_type(): void
    {
        $this->assertFalse(imap_timeout(99, 5));
    }
}
