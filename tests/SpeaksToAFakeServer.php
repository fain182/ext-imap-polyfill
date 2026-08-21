<?php

namespace ImapPolyfill\Tests;

use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use ImapPolyfill\Connection\Imap\ImapEngineConnection;
use ImapPolyfill\Connection\Protocol;

/**
 * A connection whose server is a queue of replies, for the wire behaviour no
 * fixture can produce — a command a server refuses, an announcement it never
 * makes, a byte that would end the command carrying it.
 *
 * Skipped under the real extension, which speaks its own wire and would
 * never reach this one.
 */
trait SpeaksToAFakeServer
{
    private FakeStream $stream;

    protected function setUp(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped('Exercises the polyfill\'s own wire layer, which real ext-imap does not use.');
        }
    }

    /**
     * @param string[] $responses replies the fake server sends after its greeting
     */
    private function connectionServing(array $responses): ImapEngineConnection
    {
        $this->stream = new FakeStream();
        $this->stream->open();
        $this->stream->feed(['* OK IMAP4rev1 ready', ...$responses]);

        $connection = new ImapEngineConnection($this->stream);
        $connection->connect('fake.example.com');

        return $connection;
    }

    /**
     * @param string[] $responses replies the fake server sends after its greeting
     */
    private function protocolServing(array $responses): Protocol
    {
        return new Protocol($this->connectionServing($responses), 'fake.example.com');
    }
}
