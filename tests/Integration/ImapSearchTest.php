<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapSearchTest extends GreenmailTestCase
{
    public function test_returns_matching_message_numbers(): void
    {
        $folderName = 'SearchBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello World\r\n\r\nBody text");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame([1], imap_search($connection, 'ALL'));
    }

    public function test_returns_false_when_nothing_matches(): void
    {
        $folderName = 'SearchBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello World\r\n\r\nBody text");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertFalse(imap_search($connection, 'SUBJECT nonexistent'));
    }
}
