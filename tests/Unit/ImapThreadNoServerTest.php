<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Tests\SpeaksToAFakeServer;
use PHPUnit\Framework\TestCase;

/**
 * c-client's SE_NOSERVER (0x10) makes imap_thread() thread locally even on
 * a server that offers THREAD. The two answers agree on both fixtures, so
 * only the command that does not go out can tell them apart.
 */
class ImapThreadNoServerTest extends TestCase
{
    use SpeaksToAFakeServer;

    public function test_se_noserver_threads_locally_on_a_server_that_offers_thread(): void
    {
        $protocol = $this->protocolServing([
            '* 1 EXISTS',
            '* 0 RECENT',
            'TAG1 OK [READ-WRITE] SELECT completed',
            '* 1 FETCH (UID 1 INTERNALDATE "01-Jan-2024 10:00:00 +0000" ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL) BODY[HEADER.FIELDS (REFERENCES)] "")',
            'TAG2 OK FETCH completed',
            '* CAPABILITY IMAP4rev1 THREAD=REFERENCES',
            'TAG3 OK CAPABILITY completed',
            '* THREAD (1)',
            'TAG4 OK THREAD completed',
        ]);
        $connection = \IMAP\Connection::forSession($protocol, 'INBOX', '{fake.example.com:143/imap', 'user');

        $tree = imap_thread($connection, SE_FREE | 0x10);

        $this->assertSame(['0.num' => 1, '0.next' => 0, '0.branch' => 0], $tree);
        $this->stream->assertNotWritten('THREAD');
    }
}
