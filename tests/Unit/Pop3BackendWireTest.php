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

        $this->backend = new Pop3Backend(new Pop3Protocol($client), 'localhost', '{localhost/pop3}INBOX', false);
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

    private function openedOver(bool $loser): Pop3Backend
    {
        [$client, $this->server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0) ?: throw new \RuntimeException('No socket pair');

        return new Pop3Backend(new Pop3Protocol($client), 'localhost', '{localhost/pop3}INBOX', $loser);
    }

    public function test_sizes_come_from_one_list_and_a_zero_falls_back_to_retr(): void
    {
        $this->serverReplies([
            '+OK 2 200',
            '+OK', 'TOP', '.',
            '+OK', 'Subject: a', '', '.',
            '+OK', '1 120', '2 0', '.',
            '+OK', 'Subject: b', '', '.',
            '+OK', 'Subject: b', '', 'Body', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame([1 => '120', 2 => '20'], $this->backend->fetch(['RFC822.SIZE'], [1, 2], null, UidMode::Msgno));
        $this->assertSame("STAT\r\nCAPA\r\nTOP 1 0\r\nLIST\r\nTOP 2 0\r\nRETR 2\r\n", $this->serverSaw());
    }

    /** pop3_fetchfast() reads the header first, and without TOP that is a RETR. */
    public function test_without_top_the_size_is_what_retr_sent_whatever_list_says(): void
    {
        $this->serverReplies([
            '+OK 1 100',
            '+OK', 'UIDL', '.',
            '+OK', 'Subject: a', '', 'Body', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame([1 => '20'], $this->backend->fetch(['RFC822.SIZE'], [1], null, UidMode::Msgno));
        $this->assertSame("STAT\r\nCAPA\r\nRETR 1\r\n", $this->serverSaw());
    }

    /** pop3_cache() writes the octets it read over what LIST said. */
    public function test_a_message_read_whole_reports_its_own_length(): void
    {
        $this->serverReplies([
            '+OK 1 100',
            '+OK', 'Subject: a', '', 'Body', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->backend->fetch(['BODY.PEEK[]'], [1], null, UidMode::Msgno);

        $this->assertSame([1 => '20'], $this->backend->fetch(['RFC822.SIZE'], [1], null, UidMode::Msgno));
        $this->assertSame("STAT\r\nRETR 1\r\n", $this->serverSaw());
    }

    public function test_a_server_without_capa_gets_no_list(): void
    {
        $this->serverReplies([
            '+OK 1 100',
            '-ERR unknown command',
            '+OK', 'Subject: a', '', 'Body', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame([1 => '20'], $this->backend->fetch(['RFC822.SIZE'], [1], null, UidMode::Msgno));
        $this->assertSame("STAT\r\nCAPA\r\nRETR 1\r\n", $this->serverSaw());
    }

    public function test_a_header_comes_from_top_where_the_server_advertises_it(): void
    {
        $this->serverReplies([
            '+OK 1 100',
            '+OK', 'TOP', '.',
            '+OK', 'Subject: a', '', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame([1 => "Subject: a\r\n"], $this->backend->fetch(['RFC822.HEADER'], [1], null, UidMode::Msgno));
        $this->assertSame([], $this->backend->search(SearchProgram::parse('SUBJECT b'), UidMode::Msgno));
        $this->assertSame("STAT\r\nCAPA\r\nTOP 1 0\r\n", $this->serverSaw());
    }

    public function test_a_header_comes_from_retr_where_top_is_not_advertised(): void
    {
        $this->serverReplies([
            '+OK 1 100',
            '+OK', 'UIDL', '.',
            '+OK', 'Subject: a', '', 'Body', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame([1 => "Subject: a\r\n"], $this->backend->fetch(['RFC822.HEADER'], [1], null, UidMode::Msgno));
        $this->assertSame("STAT\r\nCAPA\r\nRETR 1\r\n", $this->serverSaw());
    }

    /** pop3_cache() keeps one message's text; its header and size outlive it. */
    public function test_only_the_last_message_read_whole_is_kept(): void
    {
        $this->serverReplies([
            '+OK 2 200',
            '+OK', 'UIDL', '.',
            '+OK', 'Subject: a', '', 'Body', '.',
            '+OK', 'Subject: b', '', 'Longer body', '.',
            '+OK', 'Subject: a', '', 'Body', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame(
            [1 => "Subject: a\r\n", 2 => "Subject: b\r\n"],
            $this->backend->fetch(['RFC822.HEADER'], [1, 2], null, UidMode::Msgno),
        );
        $this->assertSame([2 => "Longer body\r\n"], $this->backend->fetch(['BODY.PEEK[TEXT]'], [2], null, UidMode::Msgno));
        $this->assertSame([1 => "Body\r\n"], $this->backend->fetch(['BODY.PEEK[TEXT]'], [1], null, UidMode::Msgno));
        $this->assertSame(
            [1 => ['RFC822.SIZE' => '20', 'RFC822.HEADER' => "Subject: a\r\n"], 2 => ['RFC822.SIZE' => '27', 'RFC822.HEADER' => "Subject: b\r\n"]],
            $this->backend->fetch(['RFC822.SIZE', 'RFC822.HEADER'], [1, 2], null, UidMode::Msgno),
        );
        $this->assertSame("STAT\r\nCAPA\r\nRETR 1\r\nRETR 2\r\nRETR 1\r\n", $this->serverSaw());
    }

    /**
     * The parsed body outlives the text too: a part of a message another one
     * has replaced is cut out of a second RETR, and a section naming no part
     * is answered from the body alone.
     */
    public function test_a_part_is_read_again_once_another_message_replaces_its_text(): void
    {
        $this->serverReplies([
            '+OK 2 200',
            '+OK', 'Subject: a', '', 'Body a', '.',
            '+OK', 'Subject: b', '', 'Body b', '.',
            '+OK', 'Subject: a', '', 'Body a', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame([1 => "Body a\r\n"], $this->backend->fetch(['BODY.PEEK[1]'], [1], null, UidMode::Msgno));
        $this->assertSame([2 => "Body b\r\n"], $this->backend->fetch(['BODY.PEEK[1]'], [2], null, UidMode::Msgno));
        $this->assertSame([1 => "Body a\r\n"], $this->backend->fetch(['BODY.PEEK[1]'], [1], null, UidMode::Msgno));
        $this->assertSame([2 => ''], $this->backend->fetch(['BODY.PEEK[2]'], [2], null, UidMode::Msgno));
        $this->assertSame("STAT\r\nRETR 1\r\nRETR 2\r\nRETR 1\r\n", $this->serverSaw());
    }

    /** A search reads each message's own text, whatever was read whole before it. */
    public function test_a_body_search_reads_again_a_message_another_has_replaced(): void
    {
        $this->serverReplies([
            '+OK 2 200',
            '+OK', 'Subject: a', '', 'Body a', '.',
            '+OK', 'Subject: b', '', 'Body b', '.',
            '+OK', 'Subject: a', '', 'Body a', '.',
            '+OK', 'Subject: b', '', 'Body b', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame([1 => "Body a\r\n"], $this->backend->fetch(['BODY.PEEK[1]'], [1], null, UidMode::Msgno));
        $this->assertSame([2 => "Body b\r\n"], $this->backend->fetch(['BODY.PEEK[1]'], [2], null, UidMode::Msgno));
        $this->assertSame([1], $this->backend->search(SearchProgram::parse('BODY "Body a"'), UidMode::Msgno));
        $this->assertSame("STAT\r\nRETR 1\r\nRETR 2\r\nRETR 1\r\nRETR 2\r\n", $this->serverSaw());
    }

    /** The parsed body's offsets hold only for the octets it was parsed from. */
    public function test_a_message_read_again_at_another_length_is_refused(): void
    {
        $this->serverReplies([
            '+OK 2 200',
            '+OK', 'Subject: a', '', 'Body a', '.',
            '+OK', 'Subject: b', '', 'Body b', '.',
            '+OK', 'Subject: a', '', 'Body a, longer', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->backend->fetch(['BODY.PEEK[1]'], [1], null, UidMode::Msgno);
        $this->backend->fetch(['BODY.PEEK[1]'], [2], null, UidMode::Msgno);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('POP3 server sent message 1 as 30 octets, after 22 earlier in the session');
        $this->backend->fetch(['BODY.PEEK[1]'], [1], null, UidMode::Msgno);
    }

    /** What RETR measured outlives the text, so LIST never shows through again. */
    public function test_a_message_read_whole_keeps_its_length_once_another_replaces_it(): void
    {
        $this->serverReplies([
            '+OK 2 200',
            '+OK', 'Subject: a', '', 'Body', '.',
            '+OK', 'Subject: b', '', 'Body', '.',
            '+OK', 'TOP', '.',
            '+OK', '1 100', '2 100', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->backend->fetch(['BODY.PEEK[]'], [1, 2], null, UidMode::Msgno);

        $this->assertSame([1 => '20'], $this->backend->fetch(['RFC822.SIZE'], [1], null, UidMode::Msgno));
        $this->assertSame("STAT\r\nRETR 1\r\nRETR 2\r\n", $this->serverSaw());
    }

    /** /loser: pop3.c trusts neither LIST nor TOP, and asks for neither. */
    public function test_a_loser_server_is_read_with_retr_alone(): void
    {
        $backend = $this->openedOver(true);
        $this->serverReplies([
            '+OK 1 100',
            '+OK', 'Subject: a', '', 'Body', '.',
        ]);
        $backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame(
            [1 => ['RFC822.SIZE' => '20', 'RFC822.HEADER' => "Subject: a\r\n"]],
            $backend->fetch(['RFC822.SIZE', 'RFC822.HEADER'], [1], null, UidMode::Msgno),
        );
        $this->assertSame("STAT\r\nRETR 1\r\n", $this->serverSaw());
    }

    public function test_headers_are_read_in_one_pipeline_where_the_server_advertises_it(): void
    {
        $this->serverReplies([
            '+OK 2 200',
            '+OK', 'TOP', 'PIPELINING', '.',
            '+OK', 'Subject: a', '', '.',
            '+OK', 'Subject: b', '', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame(
            [1 => "Subject: a\r\n", 2 => "Subject: b\r\n"],
            $this->backend->fetch(['RFC822.HEADER'], [1, 2], null, UidMode::Msgno),
        );
        $this->assertSame("STAT\r\nCAPA\r\nTOP 1 0\r\nTOP 2 0\r\n", $this->serverSaw());
    }

    /** A message a flag criterion already rules out is not read ahead. */
    public function test_a_header_search_reads_ahead_only_the_messages_the_flags_leave(): void
    {
        $this->serverReplies([
            '+OK 3 300',
            '+OK', 'TOP', 'PIPELINING', '.',
            '+OK', 'Subject: needle', '', '.',
            '+OK', 'Subject: hay', '', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);
        $this->backend->store('STORE', ['1', '+FLAGS', '(\\Seen)']);

        $this->assertSame([2], $this->backend->search(SearchProgram::parse('UNSEEN SUBJECT needle'), UidMode::Msgno));
        $this->assertSame("STAT\r\nCAPA\r\nTOP 2 0\r\nTOP 3 0\r\n", $this->serverSaw());
    }

    /** A pipelined batch of RETR would hold every text at once, where one is kept. */
    public function test_a_message_read_whole_is_never_read_ahead(): void
    {
        $this->serverReplies([
            '+OK 2 200',
            '+OK', 'Subject: a', '', 'needle', '.',
            '+OK', 'Subject: b', '', 'hay', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame([1], $this->backend->search(SearchProgram::parse('BODY needle'), UidMode::Msgno));
        $this->assertSame("STAT\r\nRETR 1\r\nRETR 2\r\n", $this->serverSaw());
    }

    /** Without TOP a header is a RETR, which is not read ahead either. */
    public function test_headers_are_not_read_ahead_without_top(): void
    {
        $this->serverReplies([
            '+OK 2 200',
            '+OK', 'PIPELINING', '.',
            '+OK', 'Subject: a', '', 'Body', '.',
            '+OK', 'Subject: b', '', 'Body', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame(
            [1 => "Subject: a\r\n", 2 => "Subject: b\r\n"],
            $this->backend->fetch(['RFC822.HEADER'], [1, 2], null, UidMode::Msgno),
        );
        $this->assertSame("STAT\r\nCAPA\r\nRETR 1\r\nRETR 2\r\n", $this->serverSaw());
    }

    /** /loser: no CAPA is read, so nothing is known to pipeline. */
    public function test_a_loser_server_is_never_pipelined(): void
    {
        $backend = $this->openedOver(true);
        $this->serverReplies([
            '+OK 2 200',
            '+OK', 'Subject: a', '', 'Body', '.',
            '+OK', 'Subject: b', '', 'Body', '.',
        ]);
        $backend->selectOrExamineFolder('INBOX', false);

        $backend->fetch(['RFC822.HEADER'], [1, 2], null, UidMode::Msgno);

        $this->assertSame("STAT\r\nRETR 1\r\nRETR 2\r\n", $this->serverSaw());
    }

    /** matches() reads a body only where FROM matched, so only the headers are read ahead. */
    public function test_a_body_after_a_header_criterion_reads_ahead_only_headers(): void
    {
        $this->serverReplies([
            '+OK 2 200',
            '+OK', 'TOP', 'PIPELINING', '.',
            '+OK', 'From: alice@example.com', '', '.',
            '+OK', 'From: bob@example.com', '', '.',
            '+OK', 'From: alice@example.com', '', 'needle', '.',
        ]);
        $this->backend->selectOrExamineFolder('INBOX', false);

        $this->assertSame([1], $this->backend->search(SearchProgram::parse('FROM alice BODY needle'), UidMode::Msgno));
        $this->assertSame("STAT\r\nCAPA\r\nTOP 1 0\r\nTOP 2 0\r\nRETR 1\r\n", $this->serverSaw());
    }
}
