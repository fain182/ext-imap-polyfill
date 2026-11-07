<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Support\ErrorStack;
use ImapPolyfill\Tests\SpeaksToAFakeServer;
use PHPUnit\Framework\TestCase;

class MessageCountTrackingTest extends TestCase
{
    use SpeaksToAFakeServer;

    public function test_an_expunge_of_a_message_the_folder_lacks_is_logged_and_the_reply_still_read(): void
    {
        $protocol = $this->protocolServing([
            '* 2 EXISTS',
            'TAG1 OK [READ-WRITE] SELECT completed',
            '* 5 EXPUNGE',
            'TAG2 OK NOOP completed',
            'TAG3 OK NOOP completed',
        ]);
        $protocol->selectOrExamineFolder('INBOX', false);
        ErrorStack::drainErrors();

        $protocol->noop();
        $protocol->noop();

        $this->assertSame(2, $protocol->selectOrExamineFolder('INBOX', false)->exists);
        $this->assertSame(['Expunge of non-existent message 5, nmsgs=2'], ErrorStack::drainErrors());
        $this->stream->assertWritten('TAG3 NOOP');
    }
}
