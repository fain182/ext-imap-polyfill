<?php

namespace ImapPolyfill\Tests\Unit;

use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use ImapPolyfill\Connection\ConnectionFailedException;
use ImapPolyfill\Connection\ConnectionLostException;
use ImapPolyfill\Connection\Imap\ImapEngineConnection;
use PHPUnit\Framework\TestCase;

/**
 * ImapEngine's connection failures, re-raised as this package's own.
 *
 * The layers above Connection\ tell three outcomes apart — a stream that
 * never opened, one the server hung up on, and a command the server refused
 * — and act differently on each: imap_open() reports the first as "Can't
 * connect to host,port: reason", imap_ping() answers the second with false
 * and an untouched error stack, and imap_reopen() answers it by dialling
 * again. None of them may name a vendor class to do it, so the translation
 * is the contract and belongs under test.
 *
 * The distinction is invisible through the real extension and awkward to
 * stage against a live fixture — a server has to hang up mid-command — so
 * it is pinned here against the stream's own metadata instead.
 */
class ConnectionFailureTranslationTest extends TestCase
{
    private FakeStream $stream;

    protected function setUp(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped('Exercises the polyfill\'s own wire layer, which real ext-imap does not use.');
        }

        $this->stream = new FakeStream();
        $this->stream->open();
    }

    private function connectedConnection(): ImapEngineConnection
    {
        $this->stream->feed('* OK IMAP4rev1 ready');

        $connection = new ImapEngineConnection($this->stream);
        $connection->connect('fake.example.com');

        return $connection;
    }

    /**
     * The greeting is read inside connect(), so a server that answers with
     * anything else fails the dial rather than the first command.
     */
    public function test_a_refused_greeting_is_a_connection_failure(): void
    {
        $this->stream->feed('* BAD this server is not accepting connections');

        $connection = new ImapEngineConnection($this->stream);

        $this->expectException(ConnectionFailedException::class);

        $connection->connect('fake.example.com', 143);
    }

    /**
     * EOF where a response was due. This is the one imap_ping() reports as
     * false without recording anything, and imap_reopen() redials on.
     */
    public function test_a_server_that_hangs_up_is_a_lost_connection(): void
    {
        $connection = $this->connectedConnection();

        // Nothing left in the buffer, and the socket says why.
        $this->stream->setMeta('eof', true);

        $this->expectException(ConnectionLostException::class);

        $connection->noop();
    }

    /**
     * A read that failed for neither EOF nor timeout is not the server
     * hanging up, and must not be mistaken for it: imap_ping() would answer
     * false and say nothing about a stream that broke for another reason.
     */
    public function test_an_unexplained_stream_error_is_a_connection_failure(): void
    {
        $connection = $this->connectedConnection();

        $this->expectException(ConnectionFailedException::class);

        $connection->noop();
    }

    /**
     * The third outcome, for contrast: the stream is fine and the server
     * simply said no. That one has been CommandFailedException all along,
     * and neither of the new types may swallow it.
     */
    public function test_a_refused_command_stays_a_command_failure(): void
    {
        $connection = $this->connectedConnection();
        $this->stream->feed('TAG1 NO cannot do that right now');

        $this->expectException(\ImapPolyfill\Connection\CommandFailedException::class);
        $this->expectExceptionMessage('cannot do that right now');

        $connection->noop();
    }
}
