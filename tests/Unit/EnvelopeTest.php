<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Message\Envelope;
use ImapPolyfill\Tests\ResetsErrorStack;
use PHPUnit\Framework\TestCase;

class EnvelopeTest extends TestCase
{
    use ResetsErrorStack;

    /**
     * What a server put in its ENVELOPE is what comes back: Cyrus keeps the
     * space a folded "In-Reply-To: \r\n <id>" leaves, and reads a Reply-To
     * of "Cloud name@gmail.com" as one mailbox with a space in it, where
     * the local parser would complain about both.
     */
    public function test_takes_the_server_envelope_as_it_is(): void
    {
        $envelope = Envelope::fromImap(
            [
                'Tue, 13 Oct 2026 09:10:30 -0400',
                'Re: Ciao',
                [['Jackpot', null, 'jackpot', 'example.net']],
                [['Jackpot', null, 'jackpot', 'example.net']],
                [[null, null, 'Cloud name', 'gmail.com']],
                null,
                null,
                null,
                ' <parent@example.com>',
                '<self@example.com>',
            ],
            "References: <root@example.com> <parent@example.com>\r\n\r\n",
        );

        $result = $envelope->toObject();

        $this->assertSame(' <parent@example.com>', $result->in_reply_to);
        $this->assertSame('"Cloud name"@gmail.com', $result->reply_toaddress);
        $this->assertSame('Cloud name', $result->reply_to[0]->mailbox);
        $this->assertSame('<root@example.com> <parent@example.com>', $result->references);
        $this->assertSame([], imap_errors() ?: []);
    }

    /**
     * Defaulting Reply-To and Sender to From is rfc822_parse_msg()'s, not
     * the server's: an ENVELOPE that says NIL is written as NIL.
     */
    public function test_leaves_a_nil_reply_to_and_sender_out(): void
    {
        $result = Envelope::fromImap(
            [null, null, [[null, null, 'a', 'b.com']], null, null, null, null, null, null, null],
            "\r\n",
        )->toObject();

        $this->assertSame(['fromaddress', 'from'], array_keys(get_object_vars($result)));
    }

    public function test_writes_a_group_from_its_start_and_end_markers(): void
    {
        $result = Envelope::fromImap(
            [null, null, null, null, null, [[null, null, 'Team', null], [null, null, 'a', 'b.com'], [null, null, null, null]], null, null, null, null],
            "\r\n",
        )->toObject();

        $this->assertSame('Team: a@b.com;', $result->toaddress);
    }
}
