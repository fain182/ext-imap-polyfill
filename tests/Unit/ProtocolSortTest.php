<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Connection\Protocol;
use ImapPolyfill\Connection\UidMode;
use ImapPolyfill\Tests\SpeaksToAFakeServer;
use PHPUnit\Framework\TestCase;

/**
 * The SORT command as it goes out on the wire. A server implementing RFC
 * 5256 correctly returns the same order the local sort would, so no
 * integration test can tell the two apart — these pin the delegation and
 * the exact command text instead.
 */
class ProtocolSortTest extends TestCase
{
    use SpeaksToAFakeServer;

    public function test_msgno_sort_sends_the_criteria_charset_and_search_program(): void
    {
        $protocol = $this->protocolServing([
            '* SORT 3 1 2',
            'TAG1 OK SORT completed',
        ]);

        $this->assertSame([3, 1, 2], $protocol->sort('DATE', 'US-ASCII', ['ALL'], UidMode::Msgno));
        $this->stream->assertWritten('TAG1 SORT (DATE) US-ASCII ALL');
    }

    /**
     * c-client sends the charset as an astring, bare when every character is
     * an ATOM-CHAR. Quoting it is enough for a strict server to reject the
     * whole command.
     */
    public function test_the_charset_goes_out_unquoted(): void
    {
        $protocol = $this->protocolServing([
            '* SORT 1',
            'TAG1 OK SORT completed',
        ]);

        $protocol->sort('REVERSE SUBJECT', 'UTF-8', ['UNSEEN'], UidMode::Msgno);

        $this->stream->assertWritten('TAG1 SORT (REVERSE SUBJECT) UTF-8 UNSEEN');
    }

    public function test_se_uid_switches_to_uid_sort(): void
    {
        $protocol = $this->protocolServing([
            '* SORT 42 7',
            'TAG1 OK SORT completed',
        ]);

        $this->assertSame([42, 7], $protocol->sort('ARRIVAL', 'US-ASCII', ['ALL'], UidMode::Uid));
        $this->stream->assertWritten('TAG1 UID SORT (ARRIVAL) US-ASCII ALL');
    }

    /**
     * A server that rejects the command outright is c-client's cue to sort
     * locally, which the null answer hands back to Session\Mailbox.
     */
    public function test_a_rejected_sort_answers_null(): void
    {
        $protocol = $this->protocolServing([
            'TAG1 BAD Sort/search command failed to parse',
        ]);

        $this->assertNull($protocol->sort('DATE', 'US-ASCII', ['ALL'], UidMode::Msgno));
    }

    /**
     * An empty result set is a successful sort of nothing, not a rejection —
     * the caller must not fall back and sort locally.
     */
    public function test_an_empty_sort_result_is_not_a_rejection(): void
    {
        $protocol = $this->protocolServing([
            '* SORT',
            'TAG1 OK SORT completed',
        ]);

        $this->assertSame([], $protocol->sort('DATE', 'US-ASCII', ['ALL'], UidMode::Msgno));
    }
}
