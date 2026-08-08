<?php

namespace ImapPolyfill\Tests\Integration;

/**
 * The upgrade path, which needs a server that advertises one: Greenmail
 * announces nothing on its cleartext port, so this fixture carries the
 * certificate (`make dovecot-up` generates it) and the announcement.
 *
 * Most of it is checked through /tls-sslv23 rather than /tls, for the reason
 * DovecotTestCase::mailboxSpec() spells out: c-client's /tls speaks TLS 1.0
 * and nothing else, so /tls is the one shape of this that cannot be compared
 * against the real extension at all. What is compared is worth more than the
 * switch used to reach it — that the upgrade happens, that it is reported,
 * and that the certificate is really validated on the upgraded socket.
 */
final class DovecotStartTlsTest extends DovecotTestCase
{
    private static function spec(string $switches, string $folder = 'INBOX'): string
    {
        return sprintf('{%s:%d/imap%s}%s', self::host(), self::port(), $switches, $folder);
    }

    private static function pop3Spec(string $switches): string
    {
        return sprintf('{%s:%d/pop3%s}INBOX', self::host(), self::pop3Port(), $switches);
    }

    public function test_the_session_ends_up_encrypted_and_says_so(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::user(), self::password());

        $this->assertInstanceOf(\IMAP\Connection::class, $connection);
        $this->assertStringContainsString('/tls', imap_check($connection)->Mailbox);

        imap_close($connection);
    }

    /**
     * The upgrade is not cosmetic: the connection has to keep working
     * afterwards, on a socket that is now encrypted.
     */
    public function test_the_upgraded_connection_still_serves_commands(): void
    {
        $folder = 'StartTls'.random_int(10000, 99999);
        $this->makeFolder($folder)->getFolder($folder)->appendMessage("Subject: Upgraded\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folder), self::user(), self::password());

        $this->assertSame(1, imap_num_msg($connection));
        $this->assertSame('Upgraded', imap_headerinfo($connection, 1)->subject);

        imap_close($connection);
    }

    /**
     * The fixture's certificate is self-signed, so an upgraded connection
     * that validates certificates must refuse it.
     *
     * Without this the whole suite could be passing with validation quietly
     * switched off: the socket is opened in cleartext and only meets the
     * certificate at the upgrade, by which time the context that governs it
     * is already fixed.
     */
    public function test_the_upgrade_validates_the_certificate_unless_told_not_to(): void
    {
        $connection = @imap_open(self::spec('/tls-sslv23'), self::user(), self::password());

        $this->assertFalse($connection);
        $this->assertIsString(imap_last_error());
    }

    /**
     * /notls is the way to refuse the upgrade, and the reported string says
     * the connection stayed in the clear.
     */
    public function test_notls_keeps_the_connection_in_the_clear(): void
    {
        $connection = imap_open(self::spec('/notls/novalidate-cert'), self::user(), self::password());

        $this->assertInstanceOf(\IMAP\Connection::class, $connection);

        $mailbox = imap_check($connection)->Mailbox;
        $this->assertStringContainsString('/notls', $mailbox);
        $this->assertStringNotContainsString('/tls/', $mailbox);

        imap_close($connection);
    }

    /**
     * No TLS switch anywhere in the spec, and the session still ends up
     * encrypted — c-client upgrades whenever a server offers it, and so does
     * this package.
     *
     * Polyfill-only, and not because the behaviour differs: c-client wants
     * the same upgrade here and cannot complete it, since the context it
     * builds for an unqualified upgrade is TLS 1.0. Against a server old
     * enough to accept that, the real extension does exactly this.
     */
    public function test_a_cleartext_spec_upgrades_itself_when_the_server_offers_it(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped("c-client's unqualified STARTTLS is TLS 1.0 only, which no current server completes.");
        }

        $connection = imap_open(self::spec('/novalidate-cert'), self::user(), self::password());

        $this->assertInstanceOf(\IMAP\Connection::class, $connection);
        $this->assertStringContainsString('/tls', imap_check($connection)->Mailbox);

        imap_close($connection);
    }

    /**
     * The POP3 upgrade is STLS, and the announcement comes back from CAPA.
     * Same TLS 1.0 limit on c-client's side, same skip.
     */
    public function test_pop3_upgrades_itself_too(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped("c-client's unqualified STLS is TLS 1.0 only, which no current server completes.");
        }

        $connection = imap_open(self::pop3Spec('/novalidate-cert'), self::user(), self::password());

        $this->assertInstanceOf(\IMAP\Connection::class, $connection);
        $this->assertStringContainsString('/tls', imap_check($connection)->Mailbox);

        imap_close($connection);
    }

    public function test_pop3_notls_keeps_the_connection_in_the_clear(): void
    {
        $connection = imap_open(self::pop3Spec('/notls/novalidate-cert'), self::user(), self::password());

        $this->assertInstanceOf(\IMAP\Connection::class, $connection);
        $this->assertStringNotContainsString('/tls/', imap_check($connection)->Mailbox);

        imap_close($connection);
    }

    public function test_pop3_refuses_the_self_signed_certificate_without_novalidate_cert(): void
    {
        $this->assertFalse(@imap_open(self::pop3Spec('/tls-sslv23'), self::user(), self::password()));
        $this->assertIsString(imap_last_error());
    }
}
