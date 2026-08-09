<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Support\Timeouts;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ImapTimeoutTest extends TestCase
{
    /**
     * Timeouts holds process-global state, the way ext-imap's own
     * mail_parameters() does; reset here so one test's setting is not the
     * next one's starting point (see ResetsErrorStack for the same problem).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new ReflectionClass(Timeouts::class);
        $reflection->getProperty('values')->setValue(null, null);
    }

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

    /**
     * Read and write are one shared value: a PHP socket has a single
     * timeout for both directions, so setting either sets the timeout and
     * reading either reports it.
     */
    public function test_setting_the_write_timeout_sets_the_read_one_too(): void
    {
        imap_timeout(IMAP_WRITETIMEOUT, 12);

        $this->assertSame(12, imap_timeout(IMAP_READTIMEOUT));
        $this->assertSame(12, Timeouts::socketSeconds());
    }

    public function test_setting_the_read_timeout_sets_the_write_one_too(): void
    {
        imap_timeout(IMAP_READTIMEOUT, 11);

        $this->assertSame(11, imap_timeout(IMAP_WRITETIMEOUT));
        $this->assertSame(11, Timeouts::socketSeconds());
    }

    /**
     * The open timeout is a different thing — it bounds the connect(), not
     * the socket — and keeps its own value.
     */
    public function test_the_open_timeout_is_not_shared(): void
    {
        imap_timeout(IMAP_READTIMEOUT, 11);
        imap_timeout(IMAP_OPENTIMEOUT, 21);

        $this->assertSame(21, imap_timeout(IMAP_OPENTIMEOUT));
        $this->assertSame(11, imap_timeout(IMAP_READTIMEOUT));
    }

    public function test_neither_asked_for_leaves_the_default(): void
    {
        $this->assertSame((int) ini_get('default_socket_timeout'), Timeouts::socketSeconds());
    }
}
