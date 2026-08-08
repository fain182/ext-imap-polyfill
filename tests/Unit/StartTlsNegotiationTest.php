<?php

namespace ImapPolyfill\Tests\Unit;

use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use ImapPolyfill\Connection\Imap\ImapEngineConnection;
use PHPUnit\Framework\TestCase;

/**
 * The STARTTLS decision, which no fixture can exercise: neither test server
 * advertises STARTTLS on its cleartext port, and one of them (Dovecot) has
 * ssl = no set precisely because c-client upgrades whenever a server offers
 * it. So the branch is pinned here, against the announcement itself.
 */
class StartTlsNegotiationTest extends TestCase
{
    private FakeStream $stream;

    protected function setUp(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped('Exercises the polyfill\'s own wire layer, which real ext-imap does not use.');
        }
    }

    /**
     * @param string[] $capabilities the atoms of the CAPABILITY answer
     */
    private function connectionServing(array $capabilities): ImapEngineConnection
    {
        $this->stream = new FakeStream();
        $this->stream->open();
        $this->stream->feed([
            '* OK IMAP4rev1 ready',
            '* CAPABILITY '.implode(' ', $capabilities),
            'TAG1 OK CAPABILITY completed',
            'TAG2 OK Begin TLS negotiation now',
            '* CAPABILITY '.implode(' ', $capabilities),
            'TAG3 OK CAPABILITY completed',
        ]);

        $connection = new ImapEngineConnection($this->stream);
        $connection->connect('fake.example.com');

        return $connection;
    }

    /**
     * No TLS switch at all: c-client still upgrades, which is the whole
     * reason the Dovecot fixture keeps its announcement off.
     */
    public function test_an_advertised_starttls_is_taken_without_being_asked(): void
    {
        $connection = $this->connectionServing(['IMAP4rev1', 'STARTTLS']);

        $connection->upgradeToTls(required: false, forbidden: false);

        $this->stream->assertWritten('TAG2 STARTTLS');
        $this->assertTrue($connection->upgradedToTls());
    }

    /**
     * A server answers an encrypted client differently from a stranger, so
     * the announcement read before the upgrade cannot be the one the gated
     * commands go on.
     */
    public function test_the_upgrade_rereads_the_capabilities(): void
    {
        $connection = $this->connectionServing(['IMAP4rev1', 'STARTTLS']);

        $connection->upgradeToTls(required: false, forbidden: false);
        $connection->capabilities();

        $this->stream->assertWritten('TAG3 CAPABILITY');
    }

    public function test_notls_leaves_an_advertised_starttls_alone(): void
    {
        $connection = $this->connectionServing(['IMAP4rev1', 'STARTTLS']);

        $connection->upgradeToTls(required: false, forbidden: true);

        $this->assertFalse($connection->upgradedToTls());
    }

    /**
     * A server with nothing to negotiate is not a reason to carry on in the
     * clear when the spec said /tls: c-client refuses the whole open.
     */
    public function test_tls_without_an_announcement_refuses_the_connection(): void
    {
        $connection = $this->connectionServing(['IMAP4rev1']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to negotiate TLS with this server');

        $connection->upgradeToTls(required: true, forbidden: false);
    }

    public function test_no_announcement_and_no_switch_stays_in_the_clear(): void
    {
        $connection = $this->connectionServing(['IMAP4rev1']);

        $connection->upgradeToTls(required: false, forbidden: false);

        $this->assertFalse($connection->upgradedToTls());
    }
}
