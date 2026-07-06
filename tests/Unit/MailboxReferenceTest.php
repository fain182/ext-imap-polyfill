<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\MailboxReference;
use PHPUnit\Framework\TestCase;

class MailboxReferenceTest extends TestCase
{
    public function test_splits_bracketed_reference_with_empty_tail(): void
    {
        $ref = MailboxReference::parse('{127.0.0.1:143/imap/novalidate-cert}');

        $this->assertSame('{127.0.0.1:143/imap/novalidate-cert}', $ref->displayPrefix);
        $this->assertSame('', $ref->bareReference);
    }

    public function test_splits_bracketed_reference_with_folder_tail(): void
    {
        $ref = MailboxReference::parse('{127.0.0.1:143/imap}INBOX.');

        $this->assertSame('{127.0.0.1:143/imap}', $ref->displayPrefix);
        $this->assertSame('INBOX.', $ref->bareReference);
    }

    public function test_treats_unbracketed_reference_as_entirely_bare(): void
    {
        $ref = MailboxReference::parse('INBOX.');

        $this->assertSame('', $ref->displayPrefix);
        $this->assertSame('INBOX.', $ref->bareReference);
    }
}
