<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapFetchheaderTest extends GreenmailTestCase
{
    public function test_returns_the_raw_header_of_a_message(): void
    {
        $folderName = 'FetchHeaderBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello World\r\n\r\nBody text");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $header = imap_fetchheader($connection, 1);

        $this->assertIsString($header);
        $this->assertStringContainsString('Subject: Hello World', $header);
        $this->assertStringNotContainsString('Body text', $header);
    }
}
