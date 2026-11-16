<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Tests\SpeaksToAFakeServer;
use PHPUnit\Framework\TestCase;

/**
 * What a local sort fetches, and what it no longer needs to, is invisible in
 * its answer, so only the commands that go out can show it.
 */
class ImapSortLocalFetchTest extends TestCase
{
    use SpeaksToAFakeServer;

    private const SELECTED = [
        '* 2 EXISTS',
        '* 0 RECENT',
        'TAG1 OK [READ-WRITE] SELECT completed',
        '* CAPABILITY IMAP4rev1',
        'TAG2 OK CAPABILITY completed',
    ];

    public function test_a_local_sort_by_size_fetches_no_envelopes(): void
    {
        $connection = $this->connectionTo([
            ...self::SELECTED,
            '* 1 FETCH (UID 7 INTERNALDATE "01-Jan-2024 10:00:00 +0000" RFC822.SIZE 900)',
            '* 2 FETCH (UID 9 INTERNALDATE "01-Jan-2024 11:00:00 +0000" RFC822.SIZE 100)',
            'TAG3 OK FETCH completed',
        ]);

        $this->assertSame([2, 1], imap_sort($connection, SORTSIZE, false));
        $this->stream->assertWritten('TAG3 FETCH 1,2 (UID INTERNALDATE RFC822.SIZE)');
    }

    /**
     * mail_sort_loadcache() keeps the keys in the message cache, so sorting
     * the same folder again fetches nothing, and a sort by size that came
     * first leaves only the envelopes to fetch for a sort by subject.
     */
    public function test_keys_stay_cached_and_envelopes_are_fetched_once_needed(): void
    {
        $connection = $this->connectionTo([
            ...self::SELECTED,
            '* 1 FETCH (UID 7 INTERNALDATE "01-Jan-2024 10:00:00 +0000" RFC822.SIZE 900)',
            '* 2 FETCH (UID 9 INTERNALDATE "01-Jan-2024 11:00:00 +0000" RFC822.SIZE 100)',
            'TAG3 OK FETCH completed',
            '* 1 FETCH (UID 7 INTERNALDATE "01-Jan-2024 10:00:00 +0000" RFC822.SIZE 900 ENVELOPE (NIL "b" NIL NIL NIL NIL NIL NIL NIL NIL))',
            '* 2 FETCH (UID 9 INTERNALDATE "01-Jan-2024 11:00:00 +0000" RFC822.SIZE 100 ENVELOPE (NIL "a" NIL NIL NIL NIL NIL NIL NIL NIL))',
            'TAG4 OK FETCH completed',
        ]);

        $this->assertSame([2, 1], imap_sort($connection, SORTSIZE, false));
        $this->assertSame([2, 1], imap_sort($connection, SORTSUBJECT, false));
        $this->assertSame([1, 2], imap_sort($connection, SORTSIZE, true));
        $this->assertSame([9, 7], imap_sort($connection, SORTSUBJECT, false, SE_UID));

        $this->stream->assertWritten('TAG3 FETCH 1,2 (UID INTERNALDATE RFC822.SIZE)');
        $this->stream->assertWritten('TAG4 FETCH 1,2 (UID INTERNALDATE RFC822.SIZE ENVELOPE)');
        $this->stream->assertNotWritten('FETCH');
    }

    /**
     * mail_sort_msgs() answers SE_UID with mail_uid() per sorted message,
     * which the sort's own fetch has already filled: the messages the
     * search left out are never asked for.
     */
    public function test_a_uid_sort_of_a_search_asks_no_uids_beyond_the_matches(): void
    {
        $connection = $this->connectionTo([
            '* 3 EXISTS',
            ...array_slice(self::SELECTED, 1),
            '* SEARCH 2',
            'TAG3 OK SEARCH completed',
            '* 2 FETCH (UID 9 INTERNALDATE "01-Jan-2024 11:00:00 +0000" RFC822.SIZE 100)',
            'TAG4 OK FETCH completed',
        ]);

        $this->assertSame([9], imap_sort($connection, SORTSIZE, false, SE_UID, 'FROM x'));
        $this->stream->assertWritten('TAG4 FETCH 2 (UID INTERNALDATE RFC822.SIZE)');
        $this->stream->assertNotWritten('FETCH');
    }

    /**
     * mail_expunged() takes the message's cache entry out, its sort keys
     * with it, and the messages after it move down a number.
     */
    public function test_an_expunge_takes_the_message_out_of_the_cached_keys(): void
    {
        $connection = $this->connectionTo([
            ...self::SELECTED,
            '* 1 FETCH (UID 7 INTERNALDATE "01-Jan-2024 10:00:00 +0000" RFC822.SIZE 900)',
            '* 2 FETCH (UID 9 INTERNALDATE "01-Jan-2024 11:00:00 +0000" RFC822.SIZE 100)',
            'TAG3 OK FETCH completed',
            '* 1 EXPUNGE',
            'TAG4 OK NOOP completed',
        ]);

        $this->assertSame([9, 7], imap_sort($connection, SORTSIZE, false, SE_UID));
        imap_ping($connection);

        $this->assertSame([9], imap_sort($connection, SORTSIZE, false, SE_UID));
        $this->assertSame([1], imap_sort($connection, SORTSIZE, false));
    }

    /**
     * @param string[] $responses
     */
    private function connectionTo(array $responses): \IMAP\Connection
    {
        return \IMAP\Connection::forSession($this->protocolServing($responses), 'INBOX', '{fake.example.com:143/imap', 'user');
    }
}
