<?php

namespace ImapPolyfill\Tests\Unit;

use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use ImapPolyfill\Connection\Imap\ImapEngineConnection;
use ImapPolyfill\Connection\Protocol;
use ImapPolyfill\Connection\UidMode;
use PHPUnit\Framework\TestCase;

/**
 * The THREAD command as it goes out on the wire, and what a rejection does.
 * The rejection path in particular has no integration equivalent: it needs a
 * server that advertises the algorithm and then refuses the command.
 */
class ProtocolThreadTest extends TestCase
{
    private FakeStream $stream;

    protected function setUp(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped('Exercises the polyfill\'s own wire layer, which real ext-imap does not use.');
        }
    }

    /**
     * @param string[] $responses
     */
    private function protocolServing(array $responses): Protocol
    {
        $this->stream = new FakeStream();
        $this->stream->open();
        $this->stream->feed(['* OK IMAP4rev1 ready', ...$responses]);

        $connection = new ImapEngineConnection($this->stream);
        $connection->connect('fake.example.com');

        return new Protocol($connection);
    }

    public function test_sends_the_algorithm_charset_and_search_program(): void
    {
        $protocol = $this->protocolServing([
            '* THREAD (1)(2 3)',
            'TAG1 OK THREAD completed',
        ]);

        $groups = $protocol->thread('REFERENCES', 'US-ASCII', ['ALL'], UidMode::MSGNO);

        $this->assertSame([[1], [2, 3]], $groups);
        $this->stream->assertWritten('TAG1 THREAD REFERENCES US-ASCII ALL');
    }

    public function test_se_uid_switches_to_uid_thread(): void
    {
        $protocol = $this->protocolServing([
            '* THREAD (7)',
            'TAG1 OK THREAD completed',
        ]);

        $protocol->thread('REFERENCES', 'US-ASCII', ['ALL'], UidMode::UID);

        $this->stream->assertWritten('TAG1 UID THREAD REFERENCES US-ASCII ALL');
    }

    public function test_nested_branches_come_back_nested(): void
    {
        $protocol = $this->protocolServing([
            '* THREAD (2 3 (4 5)(6))',
            'TAG1 OK THREAD completed',
        ]);

        $this->assertSame(
            [[2, 3, [4, 5], [6]]],
            $protocol->thread('REFERENCES', 'US-ASCII', ['ALL'], UidMode::MSGNO),
        );
    }

    /**
     * A server that advertises the algorithm can still refuse the command;
     * c-client's answer is to thread locally, which the null hands back to
     * Session\Mailbox.
     */
    public function test_a_rejected_thread_answers_null(): void
    {
        $protocol = $this->protocolServing([
            'TAG1 BAD Error in IMAP command THREAD',
        ]);

        $this->assertNull($protocol->thread('REFERENCES', 'US-ASCII', ['ALL'], UidMode::MSGNO));
    }

    /**
     * An empty result is a successful threading of nothing, not a rejection.
     */
    public function test_an_empty_thread_result_is_not_a_rejection(): void
    {
        $protocol = $this->protocolServing([
            '* THREAD',
            'TAG1 OK THREAD completed',
        ]);

        $this->assertSame([], $protocol->thread('REFERENCES', 'US-ASCII', ['ALL'], UidMode::MSGNO));
    }
}
