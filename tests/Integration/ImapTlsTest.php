<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/**
 * The encrypted connection paths. `{host:993/imap/ssl}` is what most real
 * connection strings look like, and before this class nothing ever opened
 * one successfully — the only `/ssl` tests asserted a *failure* to reach an
 * unused default port.
 */
final class ImapTlsTest extends GreenmailTestCase
{
    /**
     * The imaps port belongs to the Greenmail fixture; the Dovecot one
     * runs with ssl = no, since c-client upgrades to STARTTLS whenever a
     * server offers it.
     */
    #[Group('greenmail-only')]
    public function test_ssl_opens_a_usable_imap_connection(): void
    {
        $folderName = 'TlsBox'.random_int(10000, 99999);
        $this->makeFolder($folderName)->getFolder($folderName)->appendMessage("Subject: Over TLS\r\n\r\nBody");

        $connection = imap_open(
            sprintf('{%s:%d/imap/ssl/novalidate-cert}%s', self::host(), self::imapsPort(), $folderName),
            self::user(),
            self::password(),
        );

        $this->assertNotFalse($connection);
        $this->assertSame(1, imap_num_msg($connection));
        $this->assertSame('Over TLS', imap_headerinfo($connection, 1)->subject);

        imap_close($connection);
    }

    /**
     * Same: the pop3s port is Greenmail's.
     */
    #[Group('greenmail-only')]
    public function test_ssl_over_pop3_opens_a_usable_connection(): void
    {
        $client = self::seedClient();
        $client->getFolder('INBOX')->appendMessage("Subject: Pop3 Over TLS\r\n\r\nBody");

        $connection = imap_open(
            sprintf('{%s:%d/pop3/ssl/novalidate-cert}INBOX', self::pop3Host(), self::pop3sPort()),
            self::user(),
            self::password(),
        );

        $this->assertNotFalse($connection);
        $this->assertGreaterThan(0, imap_num_msg($connection));

        imap_close($connection);
    }

    /**
     * A certificate this fixture's chain can't validate must be refused
     * without `/novalidate-cert` — the flag has to be doing something.
     */
    public function test_ssl_without_novalidate_cert_refuses_the_self_signed_fixture(): void
    {
        $connection = @imap_open(
            sprintf('{%s:%d/imap/ssl}INBOX', self::host(), self::imapsPort()),
            self::user(),
            self::password(),
        );

        $this->assertFalse($connection);
    }
}
