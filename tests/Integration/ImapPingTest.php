<?php

namespace ImapPolyfill\Tests\Integration;

class ImapPingTest extends GreenmailTestCase
{
    public function test_returns_true_for_a_live_connection(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::user(), self::password());

        $this->assertTrue(imap_ping($connection));
    }

    /**
     * A dead stream is what imap_ping() exists to report, so noticing one
     * is an answer rather than an error: c-client returns false and leaves
     * the error stack alone.
     */
    public function test_reports_a_dropped_connection_without_recording_an_error(): void
    {
        $connection = $this->openConnectionToFolderThatThenDisappears('PingDropBox'.uniqid());
        imap_errors();

        $this->assertFalse(imap_ping($connection));
        $this->assertFalse(imap_errors());
    }

    public function test_throws_value_error_after_close(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::user(), self::password());
        imap_close($connection);

        $this->expectException(\ValueError::class);
        imap_ping($connection);
    }
}
