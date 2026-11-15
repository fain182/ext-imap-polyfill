<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Tests\SpeaksToAFakeServer;
use PHPUnit\Framework\TestCase;

/**
 * Unit-level because neither fixture lists a \Noselect folder: Gmail's
 * "[Gmail]" parent is the common one in the wild.
 */
class ImapListNoselectTest extends TestCase
{
    use SpeaksToAFakeServer;

    public function test_imap_list_leaves_a_noselect_folder_out_and_imap_getmailboxes_keeps_it(): void
    {
        $listing = [
            '* LIST (\\HasNoChildren) "/" "INBOX"',
            '* LIST (\\Noselect \\HasChildren) "/" "[Gmail]"',
            '* LIST (\\HasNoChildren) "/" "[Gmail]/Spam"',
        ];
        $protocol = $this->protocolServing([
            ...$listing,
            'TAG1 OK LIST completed',
            ...$listing,
            'TAG2 OK LIST completed',
        ]);
        $connection = \IMAP\Connection::forSession($protocol, 'INBOX', '{fake.example.com:143/imap', 'user');

        $this->assertSame(
            ['{fake.example.com:143/imap}INBOX', '{fake.example.com:143/imap}[Gmail]/Spam'],
            imap_list($connection, '{fake.example.com:143/imap}', '*'),
        );
        $this->assertSame(
            ['{fake.example.com:143/imap}INBOX', '{fake.example.com:143/imap}[Gmail]', '{fake.example.com:143/imap}[Gmail]/Spam'],
            array_map(static fn (\stdClass $folder): string => $folder->name, imap_getmailboxes($connection, '{fake.example.com:143/imap}', '*')),
        );
    }
}
