<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\MailboxSpec;
use PHPUnit\Framework\TestCase;

class MailboxSpecTest extends TestCase
{
    public function test_parses_host_port_flags_and_folder(): void
    {
        $spec = MailboxSpec::parse('{127.0.0.1:13143/imap/novalidate-cert}INBOX');

        $this->assertSame('127.0.0.1', $spec->host);
        $this->assertSame(13143, $spec->port);
        $this->assertSame(['imap', 'novalidate-cert'], $spec->flags);
        $this->assertSame('INBOX', $spec->folder);
    }

    public function test_defaults_port_to_993_when_ssl_flag_present(): void
    {
        $spec = MailboxSpec::parse('{imap.example.com/imap/ssl}INBOX');

        $this->assertSame(993, $spec->port);
        $this->assertTrue($spec->hasFlag('ssl'));
    }

    public function test_defaults_port_to_143_without_ssl_flag(): void
    {
        $spec = MailboxSpec::parse('{imap.example.com/imap}INBOX');

        $this->assertSame(143, $spec->port);
    }

    public function test_supports_subfolder_names(): void
    {
        $spec = MailboxSpec::parse('{127.0.0.1:13143/imap}INBOX.Sent');

        $this->assertSame('INBOX.Sent', $spec->folder);
    }
}
