<?php

namespace ImapPolyfill\Tests\Integration;

/**
 * Anonymous access, which needs a server that offers the SASL mechanism:
 * Greenmail advertises no AUTH= this package or c-client can use, so
 * `/anonymous` never gets past LOGIN there. The Dovecot fixture enables the
 * mechanism precisely so this path has somewhere to run.
 */
final class DovecotAnonymousTest extends DovecotTestCase
{
    /**
     * The credentials are deliberately wrong: an anonymous login must not
     * consult them, and the connection must open regardless.
     */
    public function test_anonymous_opens_a_connection_without_credentials(): void
    {
        $connection = imap_open(
            sprintf('{%s:%d/imap/anonymous/tls-sslv23/novalidate-cert}INBOX', self::host(), self::port()),
            'nobody-by-this-name',
            'not-a-password',
        );

        $this->assertInstanceOf(\IMAP\Connection::class, $connection);
        $this->assertIsInt(imap_num_msg($connection));

        imap_close($connection);
    }

    /**
     * The manual describes OP_ANONYMOUS as an NNTP .newsrc matter, but
     * mail_open() sets the same stream flag /anonymous does, and the IMAP
     * driver logs in anonymously on either.
     */
    public function test_op_anonymous_opens_the_same_way_as_the_spec_switch(): void
    {
        $connection = imap_open(
            self::mailboxSpec(),
            'nobody-by-this-name',
            'not-a-password',
            OP_ANONYMOUS,
        );

        $this->assertInstanceOf(\IMAP\Connection::class, $connection);

        imap_close($connection);
    }

    /**
     * An anonymous session has no user name to report, and c-client says so
     * in place of the /user="..." a credentialed one carries.
     */
    public function test_the_reported_mailbox_says_anonymous_instead_of_a_user(): void
    {
        $connection = imap_open(
            sprintf('{%s:%d/imap/anonymous/tls-sslv23/novalidate-cert}INBOX', self::host(), self::port()),
            self::user(),
            self::password(),
        );

        $mailbox = imap_check($connection)->Mailbox;

        // The connection upgraded on the way in, so the reported string
        // carries the /tls it negotiated as well as the switches asked for.
        $this->assertStringEndsWith('/tls/tls-sslv23/novalidate-cert/anonymous}INBOX', $mailbox);
        $this->assertStringNotContainsString('/user=', $mailbox);

        imap_close($connection);
    }
}
