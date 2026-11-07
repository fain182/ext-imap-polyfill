<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\CapturesWarnings;

/**
 * That imap_timeout() reaches the socket, rather than only being stored and
 * read back (which tests/Unit/ImapTimeoutTest.php covers).
 */
final class ImapTimeoutTest extends GreenmailTestCase
{
    use CapturesWarnings;

    protected function tearDown(): void
    {
        // Process-global, like c-client's mail_parameters.
        imap_timeout(IMAP_READTIMEOUT, (int) ini_get('default_socket_timeout'));
    }

    public function test_the_read_timeout_bounds_a_server_that_never_answers(): void
    {
        // Cleartext against the TLS port: the socket connects, then the
        // server sits waiting for a handshake that will never come and
        // sends no greeting. Only the read timeout ends this.
        imap_timeout(IMAP_READTIMEOUT, 3);
        $spec = sprintf('{%s:%d/imap/novalidate-cert}INBOX', self::host(), self::imapsPort());

        $started = microtime(true);
        $this->assertOpenFails($spec, self::user(), self::password());
        $elapsed = microtime(true) - $started;

        // Generous, since what is being excluded is a socket falling back to
        // default_socket_timeout — 60 seconds, and more than once.
        $this->assertLessThan(30, $elapsed);
    }
}
