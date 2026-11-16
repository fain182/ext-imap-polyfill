<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Tests\SpeaksToAFakeServer;
use PHPUnit\Framework\TestCase;

/**
 * Which items a local sort asks for is invisible in its answer, so only the
 * command that goes out can show that SORTSIZE leaves the envelopes alone.
 */
class ImapSortFastAttributesTest extends TestCase
{
    use SpeaksToAFakeServer;

    public function test_a_local_sort_by_size_fetches_no_envelopes(): void
    {
        $protocol = $this->protocolServing([
            '* 2 EXISTS',
            '* 0 RECENT',
            'TAG1 OK [READ-WRITE] SELECT completed',
            '* CAPABILITY IMAP4rev1',
            'TAG2 OK CAPABILITY completed',
            '* 1 FETCH (UID 7 INTERNALDATE "01-Jan-2024 10:00:00 +0000" RFC822.SIZE 900)',
            '* 2 FETCH (UID 9 INTERNALDATE "01-Jan-2024 11:00:00 +0000" RFC822.SIZE 100)',
            'TAG3 OK FETCH completed',
        ]);
        $connection = \IMAP\Connection::forSession($protocol, 'INBOX', '{fake.example.com:143/imap', 'user');

        $this->assertSame([2, 1], imap_sort($connection, SORTSIZE, false));
        $this->stream->assertWritten('TAG3 FETCH 1,2 (UID INTERNALDATE RFC822.SIZE)');
    }
}
