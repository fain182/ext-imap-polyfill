<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/**
 * That imap_timeout() reaches the socket, rather than only being stored and
 * read back (which tests/Unit/ImapTimeoutTest.php covers).
 */
final class ImapTimeoutTest extends GreenmailTestCase
{
    protected function tearDown(): void
    {
        // Process-global, like c-client's mail_parameters.
        imap_timeout(IMAP_READTIMEOUT, (int) ini_get('default_socket_timeout'));
    }

    /**
     * Greenmail-only because what this needs is a port that completes a TCP
     * connection and then says nothing — the Greenmail fixture's imaps port,
     * spoken to in the clear. The Dovecot fixture exposes no implicit-TLS
     * port at all, so under `make cross-check` nothing is listening and the
     * connection is refused rather than hanging. Both assertions would still
     * hold, on a test that had stopped exercising the timeout entirely.
     */
    #[Group('greenmail-only')]
    public function test_the_read_timeout_bounds_a_server_that_never_answers(): void
    {
        // Cleartext against the TLS port: the socket connects, then the
        // server sits waiting for a handshake that will never come and
        // sends no greeting. Only the read timeout ends this.
        imap_timeout(IMAP_READTIMEOUT, 3);
        $spec = sprintf('{%s:%d/imap/novalidate-cert}INBOX', self::host(), self::imapsPort());

        $started = microtime(true);
        $connection = @imap_open($spec, self::user(), self::password());
        $elapsed = microtime(true) - $started;

        $this->assertFalse($connection);
        // Generous, since what is being excluded is a socket falling back to
        // default_socket_timeout — 60 seconds, and more than once.
        $this->assertLessThan(30, $elapsed);
    }
}
