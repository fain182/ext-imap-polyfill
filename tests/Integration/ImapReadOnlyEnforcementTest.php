<?php

namespace ImapPolyfill\Tests\Integration;

/**
 * OP_READONLY is not enforced client-side: opening read-only only means
 * EXAMINE instead of SELECT, and the server is what refuses the writes.
 * These pin that end of the contract, which needs a server that actually
 * refuses — GreenMail only started doing so in 2.1.11.
 */
final class ImapReadOnlyEnforcementTest extends GreenmailTestCase
{
    public function test_setting_a_flag_read_only_leaves_the_message_untouched(): void
    {
        $folderName = 'ReadOnlyFlag'.random_int(10000, 99999);
        $client = $this->makeFolder($folderName);
        $client->getFolder($folderName)->appendMessage("Subject: Untouched\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password(), OP_READONLY);

        // imap_setflag_full() answers true whatever the server says — the
        // observable is the flag never landing on the message.
        imap_setflag_full($connection, '1', '\\Flagged');
        imap_close($connection);

        $client->openFolder($folderName);
        $this->assertNotContains('\\Flagged', $client->flagsOf(1));
    }

    /**
     * Reading a body over a read-only mailbox must not set \Seen, the way a
     * plain FETCH BODY[] would on a selected one.
     */
    public function test_reading_a_body_read_only_does_not_mark_it_seen(): void
    {
        $folderName = 'ReadOnlySeen'.random_int(10000, 99999);
        $client = $this->makeFolder($folderName);
        $client->getFolder($folderName)->appendMessage("Subject: Unseen\r\n\r\nBody text");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password(), OP_READONLY);

        $this->assertSame('Body text', imap_body($connection, 1));
        imap_close($connection);

        $client->openFolder($folderName);
        $this->assertNotContains('\\Seen', $client->flagsOf(1));
    }
}
