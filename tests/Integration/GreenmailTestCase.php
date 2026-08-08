<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\Support\SeedClient;

use PHPUnit\Framework\TestCase;

abstract class GreenmailTestCase extends TestCase
{
    protected static function user(): string
    {
        return getenv('IMAP_POLYFILL_TEST_USER') ?: 'testuser';
    }

    protected static function password(): string
    {
        return getenv('IMAP_POLYFILL_TEST_PASSWORD') ?: 'testpass';
    }

    /**
     * The connection flags every spec in this suite carries. Overridable so
     * the same tests can run against a server reached over TLS with a real
     * certificate, where "/novalidate-cert" would be wrong.
     */
    protected static function flags(): string
    {
        return getenv('IMAP_POLYFILL_TEST_FLAGS') ?: '/imap/novalidate-cert';
    }

    /**
     * flags() as a regex fragment for the prefix these connections report,
     * with room for a "/tls" the spec never asked for: both implementations
     * upgrade whenever the server advertises STARTTLS, and c-client writes
     * the upgrade it negotiated straight after the service name. One fixture
     * announces it and the other doesn't, so the segment is optional rather
     * than expected either way.
     */
    protected static function flagsPattern(): string
    {
        $segments = array_values(array_filter(explode('/', self::flags()), static fn (string $s): bool => $s !== ''));
        $service = array_shift($segments) ?? '';
        $rest = $segments === [] ? '' : '/'.implode('/', $segments);

        return preg_quote('/'.$service, '/').'(?:'.preg_quote('/tls', '/').')?'.preg_quote($rest, '/');
    }

    /** What the seeding client has to speak to reach the same server. */
    protected static function encryption(): string
    {
        return str_contains(self::flags(), '/ssl') ? 'ssl' : '';
    }

    protected static function host(): string
    {
        return getenv('IMAP_POLYFILL_TEST_HOST') ?: '127.0.0.1';
    }

    protected static function port(): int
    {
        return (int) (getenv('IMAP_POLYFILL_TEST_PORT') ?: 13143);
    }

    /**
     * Its own setting because the two services are one host only on a
     * fixture: a real provider puts POP3 on pop3.example.com and IMAP on
     * imap.example.com.
     */
    protected static function pop3Host(): string
    {
        return getenv('IMAP_POLYFILL_TEST_POP3_HOST') ?: self::host();
    }

    protected static function pop3Port(): int
    {
        return (int) (getenv('IMAP_POLYFILL_TEST_POP3_PORT') ?: 13110);
    }

    protected static function imapsPort(): int
    {
        return (int) (getenv('IMAP_POLYFILL_TEST_IMAPS_PORT') ?: 13993);
    }

    protected static function pop3sPort(): int
    {
        return (int) (getenv('IMAP_POLYFILL_TEST_POP3S_PORT') ?: 13995);
    }

    /** The POP3 counterpart of flags(), for the same reason. */
    protected static function pop3Flags(): string
    {
        return getenv('IMAP_POLYFILL_TEST_POP3_FLAGS') ?: '/pop3/novalidate-cert';
    }

    /**
     * $extraFlags carries the ones a single test is about (/readonly), which
     * belong after the connection flags every spec shares.
     */
    protected static function mailboxSpec(string $folder = 'INBOX', string $extraFlags = ''): string
    {
        return sprintf('{%s:%d%s%s}%s', self::host(), self::port(), self::flags(), $extraFlags, $folder);
    }

    protected static function pop3MailboxSpec(string $folder = 'INBOX', string $extraFlags = ''): string
    {
        return sprintf('{%s:%d%s%s}%s', self::pop3Host(), self::pop3Port(), self::pop3Flags(), $extraFlags, $folder);
    }

    /**
     * A client onto the same server the tests talk to, for seeding fixtures
     * without depending on the polyfill functions under test. Always built
     * here: constructed by hand it silently loses encryption() and connects
     * in cleartext to a TLS port, which does not fail — it waits.
     */
    protected static function seedClient(): SeedClient
    {
        return new SeedClient(self::host(), self::port(), self::user(), self::password(), self::encryption());
    }

    /** The same, with a fresh empty folder already created on the server. */
    protected function makeFolder(string $name): SeedClient
    {
        $client = self::seedClient();
        $client->createFolder($name);

        return $client;
    }

    /**
     * Seeds a folder where the one remaining message has msgno=1 but uid=2:
     * append a throwaway message, delete + expunge it, then append the real
     * one. Needed to prove FT_UID/ST_UID/SE_UID code paths actually key off
     * the UID rather than silently working by coincidence when uid==msgno.
     *
     * @return array{0: string, 1: int} [folder name, uid of the surviving message]
     */
    protected function makeMsgnoUidMismatchFixture(string $folderName, string $survivorMessage): array
    {
        $seedClient = $this->makeFolder($folderName);
        $folder = $seedClient->getFolder($folderName);
        $folder->appendMessage("Subject: Throwaway\r\n\r\nDiscard me");
        $seedClient->openFolder($folderName);
        $seedClient->command('STORE', ['1', '+FLAGS.SILENT', '(\\Deleted)']);
        $seedClient->expunge();
        $folder->appendMessage($survivorMessage);
        $seedClient->openFolder($folderName, true);

        $uids = $seedClient->uids();

        return [$folderName, (int) $uids[1]];
    }

    /**
     * Opens a connection to a fresh folder, then deletes that folder out from
     * under it via a second client — a realistic way to make a *subsequent*
     * operation on an otherwise still-open connection genuinely fail server
     * side, for exercising the catch/ErrorStack path of the imap_* wrappers.
     */
    protected function openConnectionToFolderThatThenDisappears(string $folderName): \IMAP\Connection
    {
        $seedClient = $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());
        $seedClient->deleteFolder($folderName);

        return $connection;
    }
}
