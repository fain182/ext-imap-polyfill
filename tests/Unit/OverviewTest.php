<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Message\Envelope;
use ImapPolyfill\Message\Overview;
use PHPUnit\Framework\TestCase;

class OverviewTest extends TestCase
{
    public function test_sets_message_id_in_reply_to_and_references_when_present(): void
    {
        $raw = "Subject: Re: Hello\r\n"
            ."Message-ID: <abc123@example.com>\r\n"
            ."In-Reply-To: <original@example.com>\r\n"
            ."References: <first@example.com> <original@example.com>\r\n"
            ."\r\n";

        $result = Overview::build(Envelope::fromHeader($raw, 'example.com'), [], '06-Jul-2026 12:00:00 +0000', 100, 1, 1);

        $this->assertSame('<abc123@example.com>', $result->message_id);
        $this->assertSame('<original@example.com>', $result->in_reply_to);
        $this->assertSame('<first@example.com> <original@example.com>', $result->references);
    }

    public function test_omits_message_id_in_reply_to_and_references_when_absent(): void
    {
        $raw = "Subject: Hello\r\n\r\n";

        $result = Overview::build(Envelope::fromHeader($raw, 'example.com'), [], '06-Jul-2026 12:00:00 +0000', 100, 1, 1);

        $this->assertObjectNotHasProperty('message_id', $result);
        $this->assertObjectNotHasProperty('in_reply_to', $result);
        $this->assertObjectNotHasProperty('references', $result);
    }
}
