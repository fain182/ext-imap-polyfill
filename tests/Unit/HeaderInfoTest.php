<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\HeaderInfo;
use PHPUnit\Framework\TestCase;

class HeaderInfoTest extends TestCase
{
    public function test_sets_message_id_in_reply_to_and_references_when_present(): void
    {
        $raw = "Subject: Re: Hello\r\n"
            ."Message-ID: <abc123@example.com>\r\n"
            ."In-Reply-To: <original@example.com>\r\n"
            ."References: <first@example.com> <original@example.com>\r\n"
            ."\r\n";

        $result = HeaderInfo::build($raw, [], '06-Jul-2026 12:00:00 +0000', '100', 1, 'example.com');

        $this->assertSame('<abc123@example.com>', $result->message_id);
        $this->assertSame('<original@example.com>', $result->in_reply_to);
        $this->assertSame('<first@example.com> <original@example.com>', $result->references);
    }

    public function test_omits_message_id_in_reply_to_and_references_when_absent(): void
    {
        $raw = "Subject: Hello\r\n\r\n";

        $result = HeaderInfo::build($raw, [], '06-Jul-2026 12:00:00 +0000', '100', 1, 'example.com');

        $this->assertObjectNotHasProperty('message_id', $result);
        $this->assertObjectNotHasProperty('in_reply_to', $result);
        $this->assertObjectNotHasProperty('references', $result);
    }

    public function test_recent_is_r_when_recent_and_seen_are_both_set(): void
    {
        $raw = "Subject: Hello\r\n\r\n";

        $result = HeaderInfo::build($raw, ['\\Recent', '\\Seen'], '06-Jul-2026 12:00:00 +0000', '100', 1, 'example.com');

        $this->assertSame('R', $result->Recent);
    }
}
