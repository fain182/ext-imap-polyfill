<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\CapturesWarnings;

class ImapUidTest extends GreenmailTestCase
{
    use CapturesWarnings;

    public function test_returns_the_uid_for_a_message_number(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'UidBox' . uniqid(),
            "Subject: Hello\r\n\r\nBody"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame($survivorUid, imap_uid($connection, 1));
    }

    public function test_returns_false_for_an_out_of_range_message_number(): void
    {
        $folderName = 'UidBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame(
            [false, ['imap_uid(): Bad message number']],
            $this->capturingWarnings(static fn () => imap_uid($connection, 999)),
        );
    }

    public function test_follows_an_expunge_and_an_append_that_leave_the_count_unchanged(): void
    {
        $folderName = 'UidBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $folder = $seedClient->getFolder($folderName);
        $folder->appendMessage("Subject: One\r\n\r\nBody");
        $folder->appendMessage("Subject: Two\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());
        imap_uid($connection, 1);

        imap_delete($connection, '1');
        imap_expunge($connection);
        imap_append($connection, self::mailboxSpec($folderName), "Subject: Three\r\n\r\nBody");

        $seedClient->openFolder($folderName, true);
        $uids = $seedClient->uids();

        $this->assertSame(2, imap_num_msg($connection));
        $this->assertSame((int) $uids[1], imap_uid($connection, 1));
        $this->assertSame((int) $uids[2], imap_uid($connection, 2));
    }
}
