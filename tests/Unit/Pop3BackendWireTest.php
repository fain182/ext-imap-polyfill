<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Connection\Pop3\Pop3Backend;
use ImapPolyfill\Connection\Pop3\Pop3Protocol;
use ImapPolyfill\Connection\UidMode;
use ImapPolyfill\Message\SearchProgram;
use PHPUnit\Framework\TestCase;

/**
 * Which POP3 commands an operation sends is invisible in its answer, so only
 * the wire can show it. The backend is handed one end of a socket pair, and
 * these play the server on the other, its replies written up front.
 */
class Pop3BackendWireTest extends TestCase
{
    /** @var resource */
    private $server;

    private Pop3Backend $backend;

    protected function setUp(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped('Exercises the polyfill\'s own POP3 backend, which real ext-imap does not use.');
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);

        [$client, $this->server] = $pair;

        $this->backend = new Pop3Backend(new Pop3Protocol($client), 'localhost', '{localhost/pop3}INBOX');
    }

    /**
     * @param list<string> $lines
     */
    private function serverReplies(array $lines): void
    {
        fwrite($this->server, implode('', array_map(static fn (string $line): string => $line."\r\n", $lines)));
    }

    private function serverSaw(): string
    {
        stream_set_blocking($this->server, false);

        return (string) stream_get_contents($this->server);
    }

    public function test_a_search_on_flags_alone_reads_no_message(): void
    {
        $this->serverReplies(['+OK 2 200']);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame([1, 2], $this->backend->search(SearchProgram::parse('UNSEEN'), UidMode::Msgno));
        $this->assertSame("STAT\r\n", $this->serverSaw());
    }
}
