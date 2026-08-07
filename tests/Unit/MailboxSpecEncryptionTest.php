<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Mailbox\MailboxSpec;
use PHPUnit\Framework\TestCase;

/**
 * Which of the two TLS flags means what. Unit-level because no test server
 * we have advertises STARTTLS, and the mapping is where the bug was: `/tls`
 * used to open a TLS socket straight onto the cleartext port, so it could
 * never connect to anything.
 */
class MailboxSpecEncryptionTest extends TestCase
{
    public function test_ssl_is_tls_from_the_first_byte(): void
    {
        $this->assertSame('ssl', MailboxSpec::parse('{imap.example.com:993/imap/ssl}INBOX')->encryption());
    }

    public function test_tls_is_starttls_on_the_cleartext_port(): void
    {
        $this->assertSame('starttls', MailboxSpec::parse('{imap.example.com:143/imap/tls}INBOX')->encryption());
    }

    public function test_no_flag_is_no_encryption(): void
    {
        $this->assertSame('', MailboxSpec::parse('{imap.example.com:143/imap}INBOX')->encryption());
    }

    /**
     * c-client checks /ssl first, so a spec carrying both connects
     * encrypted from the start rather than negotiating.
     */
    public function test_ssl_wins_over_tls(): void
    {
        $this->assertSame('ssl', MailboxSpec::parse('{imap.example.com:993/imap/ssl/tls}INBOX')->encryption());
    }

    public function test_the_flag_is_read_the_same_way_for_pop3(): void
    {
        $this->assertSame('ssl', MailboxSpec::parse('{pop.example.com:995/pop3/ssl}INBOX')->encryption());
        $this->assertSame('starttls', MailboxSpec::parse('{pop.example.com:110/pop3/tls}INBOX')->encryption());
    }
}
