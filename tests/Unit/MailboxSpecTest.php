<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Mailbox\MailboxSpec;
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

    public function test_defaults_port_to_110_for_pop3(): void
    {
        $spec = MailboxSpec::parse('{pop3.example.com/pop3}INBOX');

        $this->assertSame(110, $spec->port);
    }

    public function test_defaults_port_to_995_for_pop3_with_ssl(): void
    {
        $spec = MailboxSpec::parse('{pop3.example.com/pop3/ssl}INBOX');

        $this->assertSame(995, $spec->port);
    }

    public function test_supports_subfolder_names(): void
    {
        $spec = MailboxSpec::parse('{127.0.0.1:13143/imap}INBOX.Sent');

        $this->assertSame('INBOX.Sent', $spec->folder);
    }

    public function test_defaults_an_omitted_folder_to_inbox(): void
    {
        $spec = MailboxSpec::parse('{imap.example.com:143/imap}');

        $this->assertSame('INBOX', $spec->folder);
    }

    public function test_rejects_a_spec_without_braces(): void
    {
        $this->expectException(\ValueError::class);

        MailboxSpec::parse('INBOX');
    }

    public function test_rejects_an_empty_string(): void
    {
        $this->expectException(\ValueError::class);

        MailboxSpec::parse('');
    }

    public function test_rejects_empty_braces(): void
    {
        $this->expectException(\ValueError::class);

        MailboxSpec::parse('{}INBOX');
    }

    public function test_rejects_an_unterminated_brace(): void
    {
        $this->expectException(\ValueError::class);

        MailboxSpec::parse('{imap.example.com:143INBOX');
    }
}
