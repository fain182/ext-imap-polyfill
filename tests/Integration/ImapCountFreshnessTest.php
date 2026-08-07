<?php

namespace ImapPolyfill\Tests\Integration;

/**
 * When the message counts refresh, and when they deliberately don't.
 *
 * c-client keeps the mailbox selected and folds untagged EXISTS/RECENT into
 * its stream cache as they arrive, so imap_num_msg() is a cached read: it
 * sees what this connection has been told, not what the mailbox holds right
 * now. imap_check() is the live query. Every assertion here was taken from
 * the real extension first.
 */
final class ImapCountFreshnessTest extends GreenmailTestCase
{
    public function test_counts_follow_this_connections_own_writes(): void
    {
        $folderName = 'FreshOwn'.random_int(10000, 99999);
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame(0, imap_num_msg($connection));

        imap_append($connection, self::mailboxSpec($folderName), "Subject: Ours\r\n\r\nBody");

        $this->assertSame(1, imap_num_msg($connection));

        imap_close($connection);
    }

    /**
     * The interesting half: another session's append is *not* picked up by
     * imap_num_msg(), and is picked up by imap_check(), which leaves the
     * cached count updated behind it.
     */
    public function test_another_sessions_append_needs_a_check_to_show_up(): void
    {
        $folderName = 'FreshOther'.random_int(10000, 99999);
        $seedClient = $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);
        imap_append($connection, self::mailboxSpec($folderName), "Subject: Ours\r\n\r\nBody");

        $seedClient->getFolder($folderName)->appendMessage("Subject: Theirs\r\n\r\nBody");

        $this->assertSame(1, imap_num_msg($connection));
        $this->assertSame(2, imap_check($connection)->Nmsgs);
        $this->assertSame(2, imap_num_msg($connection));

        imap_close($connection);
    }

    /**
     * Expunging tells the connection which message went away, one untagged
     * response per message, rather than a new total.
     */
    public function test_expunging_lowers_the_count(): void
    {
        $folderName = 'FreshExpunge'.random_int(10000, 99999);
        $folder = $this->makeFolder($folderName)->getFolder($folderName);
        $folder->appendMessage("Subject: One\r\n\r\nBody");
        $folder->appendMessage("Subject: Two\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);
        $this->assertSame(2, imap_num_msg($connection));

        imap_delete($connection, '1');
        imap_expunge($connection);

        $this->assertSame(1, imap_num_msg($connection));

        imap_close($connection);
    }
}
