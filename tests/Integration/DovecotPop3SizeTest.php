<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\Support\SeedClient;

/**
 * The size a POP3 message reports where the server advertises TOP, which
 * Greenmail does not: there pop3_fetchfast() reads the header with TOP, and
 * the figure pop3_open() took from LIST survives until the message is read
 * whole. Dovecot's LIST counts the message as stored, without the CRLF its
 * RETR adds to one that lacks a final line break, so the two can be told
 * apart.
 */
final class DovecotPop3SizeTest extends DovecotTestCase
{
    public function test_the_size_is_the_one_list_gives_until_the_message_is_read(): void
    {
        $raw = 'Subject: DovecotPop3Size'.uniqid()."\r\n\r\nNo final line break";
        (new SeedClient(self::host(), self::port(), self::user(), self::password()))->getFolder('INBOX')->appendMessage($raw);

        $connection = imap_open(
            sprintf('{%s:%d/pop3/tls-sslv23/novalidate-cert}INBOX', self::host(), self::pop3Port()),
            self::user(),
            self::password(),
        );
        $count = imap_num_msg($connection);

        $this->assertSame((string) strlen($raw), imap_headerinfo($connection, $count)->Size);

        imap_body($connection, $count);

        $this->assertSame((string) strlen($raw."\r\n"), imap_headerinfo($connection, $count)->Size);

        imap_close($connection);
    }
}
