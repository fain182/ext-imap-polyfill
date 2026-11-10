<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Connection\UidMode;
use ImapPolyfill\Tests\SpeaksToAFakeServer;
use PHPUnit\Framework\TestCase;

/**
 * c-client files every FETCH response under the message it names, whatever
 * command is in flight, and the caller reads its own message back. These
 * pin the answers a fallback used to make up instead.
 */
class ProtocolFetchTest extends TestCase
{
    use SpeaksToAFakeServer;

    public function test_a_fetch_for_a_message_not_asked_about_answers_nothing(): void
    {
        $protocol = $this->selected([
            '* 3 FETCH (FLAGS (\Seen))',
            'TAG2 OK FETCH completed',
        ]);

        $this->assertSame([], $protocol->fetch(['RFC822.HEADER'], [2], null, UidMode::Msgno));
    }

    public function test_a_flag_update_for_the_message_asked_about_does_not_replace_its_answer(): void
    {
        $protocol = $this->selected([
            '* 2 FETCH (RFC822.HEADER "Subject: x")',
            '* 2 FETCH (FLAGS (\Seen))',
            'TAG2 OK FETCH completed',
        ]);

        $this->assertSame([2 => 'Subject: x'], $protocol->fetch(['RFC822.HEADER'], [2], null, UidMode::Msgno));
    }

    public function test_an_unsolicited_fetch_without_a_uid_answers_no_uid_fetch(): void
    {
        $protocol = $this->selected([
            '* 1 FETCH (FLAGS (\Seen))',
            '* 2 FETCH (UID 20 RFC822.HEADER "Subject: x")',
            'TAG2 OK FETCH completed',
        ]);

        $this->assertSame([20 => 'Subject: x'], $protocol->fetch(['RFC822.HEADER'], [20], null, UidMode::Uid));
    }

    public function test_item_names_are_read_whatever_their_case(): void
    {
        $protocol = $this->selected([
            '* 2 FETCH (rfc822.header "Subject: x")',
            'TAG2 OK FETCH completed',
        ]);

        $this->assertSame([2 => 'Subject: x'], $protocol->fetch(['RFC822.HEADER'], [2], null, UidMode::Msgno));
    }

    public function test_a_message_answered_without_an_item_asked_for_is_a_failure(): void
    {
        $protocol = $this->selected([
            '* 2 FETCH (FLAGS (\Seen) RFC822.SIZE 10)',
            'TAG2 OK FETCH completed',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FETCH response for 2 lacks RFC822.HEADER');

        $protocol->fetch(['FLAGS', 'RFC822.SIZE', 'RFC822.HEADER'], [2], null, UidMode::Msgno);
    }

    /**
     * @param list<string> $fetchReply
     */
    private function selected(array $fetchReply): \ImapPolyfill\Connection\Protocol
    {
        $protocol = $this->protocolServing([
            '* 3 EXISTS',
            'TAG1 OK [READ-WRITE] SELECT completed',
            ...$fetchReply,
        ]);
        $protocol->selectOrExamineFolder('INBOX', false);

        return $protocol;
    }
}
