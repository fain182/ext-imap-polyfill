<?php

namespace ImapPolyfill\Tests\Integration;

class ImapMsgnoTest extends GreenmailTestCase
{
    public function test_returns_the_message_number_for_a_known_uid(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'MsgnoBox' . uniqid(),
            "Subject: Hello\r\n\r\nBody"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame(1, imap_msgno($connection, $survivorUid));
    }

    public function test_returns_zero_for_an_unknown_uid(): void
    {
        $folderName = 'MsgnoBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame(0, imap_msgno($connection, 999999));
    }
}
