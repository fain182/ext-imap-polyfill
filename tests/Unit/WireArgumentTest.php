<?php

namespace ImapPolyfill\Tests\Unit;

use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use ImapPolyfill\Connection\Imap\ImapEngineConnection;
use ImapPolyfill\Connection\Protocol;
use ImapPolyfill\Connection\UidMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a caller's string is allowed to put on the wire. Some arguments go
 * out bare — a flag list, a body section, a message sequence — and a line
 * break in one of those would end the command and start a second one, in a
 * session that has already logged in.
 *
 * Host-only: a divergence from the extension, which sends those bytes.
 */
class WireArgumentTest extends TestCase
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
    private function protocolServing(array $responses): Protocol
    {
        $this->stream = new FakeStream();
        $this->stream->open();
        $this->stream->feed(['* OK IMAP4rev1 ready', ...$responses]);

        $connection = new ImapEngineConnection($this->stream);
        $connection->connect('fake.example.com');

        return new Protocol($connection, 'fake.example.com');
    }

    /**
     * @return iterable<string, array{\Closure(Protocol): void, string}>
     */
    public static function bareArguments(): iterable
    {
        yield 'a flag' => [
            fn (Protocol $p) => $p->store('STORE', ['1', '+FLAGS.SILENT', "(\\Seen)\r\nX9 STORE 1:* +FLAGS (\\Deleted)"]),
            'X9 STORE',
        ];

        yield 'a body section' => [
            fn (Protocol $p) => $p->fetch(["BODY[1]\r\nX9 LOGOUT"], [1], null, UidMode::MSGNO),
            'X9 LOGOUT',
        ];

        yield 'a message sequence' => [
            fn (Protocol $p) => $p->copy("1\r\nX9 EXPUNGE", 'Archive', UidMode::MSGNO),
            'X9 EXPUNGE',
        ];
    }

    /**
     * @param \Closure(Protocol): void $call
     */
    #[DataProvider('bareArguments')]
    public function test_an_argument_carrying_a_line_break_never_reaches_the_wire(\Closure $call, string $injected): void
    {
        $protocol = $this->protocolServing([]);

        try {
            $call($protocol);
            $this->fail('Expected the line break to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Command argument contains a line break', $e->getMessage());
        }

        $this->stream->assertNotWritten($injected);
    }

    /** The refusal is about the shape, not the bytes: a literal is exempt. */
    public function test_an_appended_message_still_travels_with_its_line_breaks(): void
    {
        $protocol = $this->protocolServing([
            '+ Ready for literal data',
            'TAG1 OK APPEND completed',
        ]);

        $protocol->appendMessage('INBOX', "Subject: hi\r\n\r\nbody\r\n", null, null);

        $this->stream->assertWritten("Subject: hi\r\n\r\nbody\r\n");
    }

    /** The user-facing half: true whatever happened, reason on the stack. */
    public function test_imap_setflag_full_answers_true_and_leaves_the_reason_on_the_stack(): void
    {
        $protocol = $this->protocolServing([
            '* 3 EXISTS',
            'TAG1 OK [READ-WRITE] SELECT completed',
        ]);
        $connection = \IMAP\Connection::forSession($protocol, 'INBOX', '{fake.example.com:143/imap', 'user');

        $this->assertTrue(imap_setflag_full($connection, '1', "\\Seen)\r\nX9 STORE 1:* +FLAGS (\\Deleted"));
        $this->assertSame('Command argument contains a line break', imap_last_error());
        $this->stream->assertNotWritten('X9 STORE');
    }
}
