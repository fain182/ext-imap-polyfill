<?php

namespace Fain182\ImapPolyfill\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ImapRfc822ParseHeadersTest extends TestCase
{
    public function test_parses_from_to_and_subject(): void
    {
        $raw = "Subject: Hello\r\nFrom: Joe Doe <joe@example.com>\r\nTo: jane@example.com\r\n\r\n";

        $result = imap_rfc822_parse_headers($raw);

        $this->assertSame('Hello', $result->subject);
        $this->assertSame('Hello', $result->Subject);
        $this->assertSame('joe', $result->from[0]->mailbox);
        $this->assertSame('Joe Doe <joe@example.com>', $result->fromaddress);
        $this->assertSame('jane', $result->to[0]->mailbox);
    }

    public function test_has_no_connection_state_properties(): void
    {
        $raw = "Subject: Hello\r\n\r\n";

        $result = imap_rfc822_parse_headers($raw);

        $this->assertObjectNotHasProperty('Recent', $result);
        $this->assertObjectNotHasProperty('Msgno', $result);
        $this->assertObjectNotHasProperty('udate', $result);
    }

    public function test_defaults_host_to_unknown(): void
    {
        $raw = "From: joe\r\n\r\n";

        $result = imap_rfc822_parse_headers($raw);

        $this->assertSame('UNKNOWN', $result->from[0]->host);
    }

    public function test_uses_the_given_default_hostname(): void
    {
        $raw = "From: joe\r\n\r\n";

        $result = imap_rfc822_parse_headers($raw, 'example.com');

        $this->assertSame('example.com', $result->from[0]->host);
    }
}
