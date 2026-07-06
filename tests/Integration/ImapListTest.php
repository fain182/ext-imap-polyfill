<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapListTest extends GreenmailTestCase
{
    public function test_returns_full_mailbox_names_matching_the_pattern(): void
    {
        $folderName = 'ListBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::USER, self::PASSWORD);

        $result = imap_list($connection, self::mailboxSpec(''), '*');

        $this->assertIsArray($result);
        $this->assertContains(self::mailboxSpec($folderName), $result);
    }
}
