<?php

namespace ImapPolyfill\Tests\Integration;

class ImapPingTest extends GreenmailTestCase
{
    public function test_returns_true_for_a_live_connection(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);

        $this->assertTrue(imap_ping($connection));
    }

    public function test_throws_value_error_after_close(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);
        imap_close($connection);

        $this->expectException(\ValueError::class);
        imap_ping($connection);
    }
}
