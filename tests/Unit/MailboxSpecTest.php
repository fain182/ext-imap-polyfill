<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Mailbox\MailboxSpec;
use ImapPolyfill\Mailbox\Service;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The spec parser, against c-client's mail_valid_net_parse_work() (mail.c).
 *
 * Most of what is pinned here is what the parser *refuses*: the switch set is
 * closed, and a spec naming something outside it is not opened with the
 * switch ignored — imap_open() returns false and leaves "invalid remote
 * specification" on the error stack. Unit-level because reaching these paths
 * through imap_open() would mean dialing hosts that don't answer.
 */
class MailboxSpecTest extends TestCase
{
    public function test_parses_host_port_switches_and_folder(): void
    {
        $spec = MailboxSpec::parse('{127.0.0.1:13143/imap/novalidate-cert}INBOX');

        $this->assertSame('127.0.0.1', $spec->host);
        $this->assertSame(13143, $spec->port);
        $this->assertSame(Service::Imap, $spec->service);
        $this->assertTrue($spec->switches->novalidate);
        $this->assertSame('INBOX', $spec->folder);
    }

    public function test_defaults_port_to_993_when_ssl_flag_present(): void
    {
        $spec = MailboxSpec::parse('{imap.example.com/imap/ssl}INBOX');

        $this->assertSame(993, $spec->port);
        $this->assertTrue($spec->switches->ssl);
    }

    public function test_defaults_port_to_143_without_ssl_flag(): void
    {
        $this->assertSame(143, MailboxSpec::parse('{imap.example.com/imap}INBOX')->port);
    }

    public function test_defaults_port_to_110_for_pop3(): void
    {
        $this->assertSame(110, MailboxSpec::parse('{pop3.example.com/pop3}INBOX')->port);
    }

    public function test_defaults_port_to_995_for_pop3_with_ssl(): void
    {
        $this->assertSame(995, MailboxSpec::parse('{pop3.example.com/pop3/ssl}INBOX')->port);
    }

    public function test_supports_subfolder_names(): void
    {
        $this->assertSame('INBOX.Sent', MailboxSpec::parse('{127.0.0.1:13143/imap}INBOX.Sent')->folder);
    }

    public function test_defaults_an_omitted_folder_to_inbox(): void
    {
        $this->assertSame('INBOX', MailboxSpec::parse('{imap.example.com:143/imap}')->folder);
    }

    public function test_keeps_a_bracketed_domain_literal_whole(): void
    {
        $spec = MailboxSpec::parse('{[::1]:143/imap}INBOX');

        $this->assertSame('[::1]', $spec->host);
        $this->assertSame(143, $spec->port);
    }

    /**
     * The port is a switch like any other, so it may follow them as easily as
     * precede them — c-client scans one run of delimiters, not "host:port"
     * and then a flag list.
     */
    public function test_reads_a_port_that_follows_the_switches(): void
    {
        $spec = MailboxSpec::parse('{imap.example.com/imap/ssl:993}INBOX');

        $this->assertSame(993, $spec->port);
        $this->assertTrue($spec->switches->ssl);
    }

    public function test_switch_names_are_case_insensitive(): void
    {
        $spec = MailboxSpec::parse('{imap.example.com/IMAP/NoValidate-Cert/ReadOnly}INBOX');

        $this->assertSame(Service::Imap, $spec->service);
        $this->assertTrue($spec->switches->novalidate);
        $this->assertTrue($spec->switches->readOnly);
    }

    /**
     * Implicit TLS leaves no cleartext phase to upgrade, so /ssl carries
     * /notls with it (imap4r1.c reports both back in stream->mailbox).
     */
    public function test_ssl_implies_notls(): void
    {
        $this->assertTrue(MailboxSpec::parse('{imap.example.com/imap/ssl}INBOX')->switches->notls);
    }

    public function test_collects_the_switches_that_only_travel_to_the_reported_mailbox(): void
    {
        $spec = MailboxSpec::parse('{imap.example.com/imap/debug/loser/tryssl/norsh/secure/anonymous}INBOX');

        $this->assertTrue($spec->switches->debug);
        $this->assertTrue($spec->switches->loser);
        $this->assertTrue($spec->switches->tryssl);
        $this->assertTrue($spec->switches->norsh);
        $this->assertTrue($spec->switches->secure);
        $this->assertTrue($spec->switches->anonymous);
    }

    /**
     * Kept for specs written when it meant something; certificates are
     * validated unless /novalidate-cert says otherwise.
     */
    public function test_validate_cert_is_accepted_and_inert(): void
    {
        $this->assertFalse(MailboxSpec::parse('{imap.example.com/imap/validate-cert}INBOX')->switches->novalidate);
    }

    public function test_reads_the_user_and_authuser_arguments(): void
    {
        $spec = MailboxSpec::parse('{imap.example.com/imap/user=alice/authuser=admin}INBOX');

        $this->assertSame('alice', $spec->switches->user);
        $this->assertSame('admin', $spec->switches->authuser);
    }

    public function test_reads_a_quoted_argument_with_escapes(): void
    {
        $spec = MailboxSpec::parse('{imap.example.com/imap/user="alice/bob\\"jr"}INBOX');

        $this->assertSame('alice/bob"jr', $spec->switches->user);
    }

    public function test_service_argument_names_the_driver(): void
    {
        $spec = MailboxSpec::parse('{pop.example.com/service=pop3}INBOX');

        $this->assertSame(Service::Pop3, $spec->service);
        $this->assertSame(110, $spec->port);
    }

    /**
     * @param non-empty-string $spec
     */
    #[DataProvider('serviceAliases')]
    public function test_service_aliases_resolve_to_their_driver(string $spec, Service $service): void
    {
        $this->assertSame($service, MailboxSpec::parse($spec)->service);
    }

    /**
     * @return iterable<string, array{string, Service}>
     */
    public static function serviceAliases(): iterable
    {
        yield 'imap2' => ['{host/imap2}INBOX', Service::Imap];
        yield 'imap2bis' => ['{host/imap2bis}INBOX', Service::Imap];
        yield 'imap4' => ['{host/imap4}INBOX', Service::Imap];
        yield 'imap4rev1' => ['{host/imap4rev1}INBOX', Service::Imap];
        yield 'pop' => ['{host/pop}INBOX', Service::Pop3];
        yield 'nntp' => ['{host/nntp}INBOX', Service::Nntp];
    }

    /**
     * @param non-empty-string $spec
     */
    #[DataProvider('refusedSpecs')]
    public function test_refuses_the_specs_c_client_refuses(string $spec): void
    {
        $this->expectException(\ValueError::class);

        MailboxSpec::parse($spec);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedSpecs(): iterable
    {
        yield 'no braces' => ['INBOX'];
        yield 'empty string' => [''];
        yield 'empty braces' => ['{}INBOX'];
        yield 'unterminated brace' => ['{imap.example.com:143INBOX'];
        yield 'unterminated domain literal' => ['{[::1}INBOX'];

        yield 'misspelled switch' => ['{host/nowalidate-cert}INBOX'];
        yield 'unknown switch' => ['{host/imap/banana}INBOX'];
        yield 'unknown argument switch' => ['{host/imap/password=hunter2}INBOX'];
        yield 'unterminated quoted argument' => ['{host/imap/user="alice}INBOX'];

        yield 'port zero' => ['{host:0/imap}INBOX'];
        yield 'port with no digits' => ['{host:/imap}INBOX'];
        yield 'port given twice' => ['{host:143/imap:993}INBOX'];

        yield 'notls then tls' => ['{host/notls/tls}INBOX'];
        yield 'tls then notls' => ['{host/tls/notls}INBOX'];

        yield 'two services' => ['{host/imap/pop3}INBOX'];
        yield 'service switch after service argument' => ['{host/service=imap/pop3}INBOX'];
        yield 'user given twice' => ['{host/user=alice/user=bob}INBOX'];

        // Parsed by c-client, then refused a moment later by mail_valid(),
        // which finds no remote driver for them — the same error either way.
        yield 'smtp' => ['{host/smtp}INBOX'];
        yield 'submit' => ['{host/submit}INBOX'];
        yield 'unknown service argument' => ['{host/service=banana}INBOX'];

        // /norsh disables the rsh preauth, which only the IMAP driver has.
        yield 'norsh over pop3' => ['{host/pop3/norsh}INBOX'];

        yield 'user argument too long' => ['{host/user='.str_repeat('a', 65).'}INBOX'];
        yield 'folder too long' => ['{host}'.str_repeat('a', 256)];
        yield 'host too long' => ['{'.str_repeat('a', 256).'}INBOX'];
    }

    /**
     * The text is c-client's, "%.80s" truncation included: it is what lands
     * on the error stack, so imap_last_error() shows it verbatim.
     */
    public function test_the_refusal_carries_c_clients_message(): void
    {
        $this->expectExceptionMessage("Can't open mailbox {host/banana}INBOX: invalid remote specification");

        MailboxSpec::parse('{host/banana}INBOX');
    }

    public function test_the_refusal_truncates_a_long_spec_at_eighty_characters(): void
    {
        $spec = '{host/banana}'.str_repeat('x', 200);

        $this->expectExceptionMessage("Can't open mailbox ".substr($spec, 0, 80).': invalid remote specification');

        MailboxSpec::parse($spec);
    }
}
