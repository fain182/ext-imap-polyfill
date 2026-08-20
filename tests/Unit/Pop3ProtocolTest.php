<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Connection\Pop3\Pop3Protocol;
use PHPUnit\Framework\TestCase;

/**
 * The POP3 client's own line handling, on both sides of the socket: what it
 * writes for a login, and what it will read back from a server.
 *
 * There is no seam to hand it a fake connection — it owns a socket and
 * dials it itself — so these hand it one end of a socket pair and play the
 * server on the other. Host-only: both behaviors are the polyfill's, not
 * the extension's.
 */
class Pop3ProtocolTest extends TestCase
{
    /** @var resource */
    private $server;

    private Pop3Protocol $protocol;

    protected function setUp(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped('Exercises the polyfill\'s own POP3 client, which real ext-imap does not use.');
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);

        [$client, $this->server] = $pair;

        $this->protocol = new Pop3Protocol();
        (new \ReflectionProperty(Pop3Protocol::class, 'stream'))->setValue($this->protocol, $client);
    }

    private function serverSaw(): string
    {
        stream_set_blocking($this->server, false);

        return (string) stream_get_contents($this->server);
    }

    public function test_a_login_is_two_commands(): void
    {
        fwrite($this->server, "+OK user accepted\r\n+OK logged in\r\n");

        $this->protocol->login('alice', 's3cret');

        $this->assertSame("USER alice\r\nPASS s3cret\r\n", $this->serverSaw());
    }

    /**
     * A user name and a password are the two arguments this client formats
     * into a command line that did not come from it, and in an application
     * that logs a person in they came from a form.
     */
    public function test_a_password_carrying_a_line_break_is_refused_before_anything_is_sent(): void
    {
        fwrite($this->server, "+OK user accepted\r\n");

        try {
            $this->protocol->login('alice', "s3cret\r\nDELE 1");
            $this->fail('Expected the line break to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Command argument contains a line break', $e->getMessage());
        }

        // USER went out, since it carried nothing; PASS never did, and
        // neither did the command hiding behind it.
        $this->assertSame("USER alice\r\n", $this->serverSaw());
    }

    public function test_a_user_name_carrying_a_line_break_is_refused_before_anything_is_sent(): void
    {
        try {
            $this->protocol->login("alice\r\nDELE 1", 's3cret');
            $this->fail('Expected the line break to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Command argument contains a line break', $e->getMessage());
        }

        $this->assertSame('', $this->serverSaw());
    }

    /**
     * RFC 1939 gives a status line 512 octets. Reading one without a
     * ceiling is reading until the server ends the line, which a server
     * that never ends it turns into this process's whole memory.
     */
    public function test_a_status_line_that_never_ends_is_refused(): void
    {
        fwrite($this->server, str_repeat('+OK padding ', 800));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('POP3 status line too long');

        $this->protocol->login('alice', 's3cret');
    }

    public function test_a_connection_that_stops_mid_line_is_not_a_long_line(): void
    {
        fwrite($this->server, '+OK no newline here');
        // Half-closed rather than closed: the client's own USER still has
        // somewhere to go, and the read that follows it meets the end.
        stream_socket_shutdown($this->server, STREAM_SHUT_WR);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('POP3 connection closed unexpectedly');

        $this->protocol->login('alice', 's3cret');
    }
}
