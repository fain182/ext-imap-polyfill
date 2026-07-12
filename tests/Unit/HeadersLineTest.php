<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Message\HeadersLine;
use PHPUnit\Framework\TestCase;

/**
 * Unit-level on purpose: the "{flag}" segment only renders keywords the
 * session saw in a SELECT FLAGS response, and GreenMail never lists
 * custom keywords there, so no integration scenario can produce it.
 */
class HeadersLineTest extends TestCase
{
    public function test_renders_registered_keywords_between_from_and_subject_in_registration_order(): void
    {
        $line = HeadersLine::build(
            "From: Alice <alice@example.com>\r\nSubject: Hello\r\n\r\n",
            ['KeyB', '\\Seen', 'KeyA', 'NeverRegistered'],
            '12-Jul-2026 10:00:00 +0000',
            42,
            1,
            'example.com',
            ['KeyA', 'KeyB'],
        );

        $this->assertStringContainsString('Alice                {KeyA KeyB} Hello (42 chars)', $line);
    }

    public function test_renders_no_flag_segment_when_no_keyword_is_registered(): void
    {
        $line = HeadersLine::build(
            "From: Alice <alice@example.com>\r\nSubject: Hello\r\n\r\n",
            ['SomeKeyword', '\\Seen'],
            '12-Jul-2026 10:00:00 +0000',
            42,
            1,
            'example.com',
        );

        $this->assertStringContainsString('Alice                Hello (42 chars)', $line);
    }
}
