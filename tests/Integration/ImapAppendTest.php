<?php

namespace ImapPolyfill\Tests\Integration;

class ImapAppendTest extends GreenmailTestCase
{
    public function test_appends_a_message_to_the_folder(): void
    {
        $folderName = 'AppendBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $result = imap_append($connection, self::mailboxSpec($folderName), "Subject: Appended\r\n\r\nBody text\r\n");

        $this->assertTrue($result);
        $this->assertSame(1, imap_num_msg($connection));
    }

    public function test_appends_a_message_with_flags(): void
    {
        $folderName = 'AppendBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        imap_append($connection, self::mailboxSpec($folderName), "Subject: Appended\r\n\r\nBody text\r\n", '\\Seen');

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(1, $overview[0]->seen);
    }

    public function test_preserves_a_supplied_internal_date(): void
    {
        $folderName = 'AppendBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $timestamp = 1700000000;
        imap_append(
            $connection,
            self::mailboxSpec($folderName),
            "Subject: Dated\r\n\r\nBody text\r\n",
            null,
            date('d-M-Y H:i:s O', $timestamp)
        );

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame($timestamp, $overview[0]->udate);
    }

    /**
     * A name starting with "{" that is not a spec never reaches the server.
     * Sent as it stands, "{x" is the start of a literal there, and the
     * connection would not survive the server waiting for its bytes.
     */
    public function test_refuses_a_prefix_that_is_not_a_spec_without_sending_it(): void
    {
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());
        imap_errors();
        $mailbox = '{x'.uniqid();

        $this->assertFalse(imap_append($connection, $mailbox, "Subject: x\r\n\r\nx\r\n"));
        $this->assertSame(["Can't append to mailbox {$mailbox}: invalid remote specification"], imap_errors());
        $this->assertTrue(imap_ping($connection));
    }
}
