<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Message\BaseSubject;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes the mail_strip_subject() port against RFC 5256 §2.1.
 * Unit-level on purpose: imap_sort(SORTSUBJECT) can't pin these cases in
 * the parity suite, because real ext-imap hands sorting to the server
 * when it advertises SORT, and GreenMail's SORT compares raw subjects.
 */
class BaseSubjectTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function baseSubjects(): array
    {
        return [
            'plain subject' => ['Hello world', 'hello world'],
            'simple re' => ['Re: Hello', 'hello'],
            'fwd prefix' => ['FWD: x', 'x'],
            'fw prefix' => ['Fw: x', 'x'],
            'blob between re and colon' => ['re [2]: banana', 'banana'],
            'non-numeric blob in fw marker' => ['fw [x]: cherry', 'cherry'],
            'leader chain with trailer' => ['[list] Re: [tag] Topic (fwd)', 'topic'],
            'netscape fwd wrapper' => ['[Fwd: apple pie]', 'apple pie'],
            'fwd wrapper with inner blob' => ['[Fwd: [x] hi]', 'hi'],
            'fwd-looking blob with text after is a plain blob' => ['[Fwd: hi] there', 'there'],
            'bare blob leaving empty base is kept' => ['[tag]', '[tag]'],
            'malformed nested blob voids the marker' => ['[bad [nested] blob', '[bad [nested] blob'],
            'whitespace collapse' => ["  spaced \t  out  ", 'spaced out'],
            'reply is not re' => ['Reply: no', 'reply: no'],
            'fwd trailer chain' => ['topic (fwd) (FWD) ', 'topic'],
            'leading (fwd) is not a trailer' => ['(fwd) leading', '(fwd) leading'],
            'marker only' => ['re:', ''],
        ];
    }

    /**
     * @dataProvider baseSubjects
     */
    public function test_extracts_the_rfc5256_base_subject(string $subject, string $expected): void
    {
        $this->assertSame($expected, BaseSubject::of($subject));
    }

    public function test_detects_re_fwd_markers(): void
    {
        $this->assertTrue(BaseSubject::isReplyOrForward('Re: x'));
        $this->assertTrue(BaseSubject::isReplyOrForward('x (FWD)'));
        $this->assertTrue(BaseSubject::isReplyOrForward('[Fwd: x]'));
        $this->assertTrue(BaseSubject::isReplyOrForward('re [2]: x'));
        $this->assertFalse(BaseSubject::isReplyOrForward('plain'));
        $this->assertFalse(BaseSubject::isReplyOrForward('Reply: no'));
        $this->assertFalse(BaseSubject::isReplyOrForward('[tag] plain'));
    }
}
