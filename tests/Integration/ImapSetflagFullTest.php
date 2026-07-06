<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapSetflagFullTest extends GreenmailTestCase
{
    public function test_sets_flags_on_a_message(): void
    {
        $folderName = 'SetFlagBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_setflag_full($connection, '1', '\\Seen \\Flagged');

        $this->assertTrue($result);

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(1, $overview[0]->seen);
        $this->assertSame(1, $overview[0]->flagged);
    }
}
