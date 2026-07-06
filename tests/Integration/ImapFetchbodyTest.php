<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapFetchbodyTest extends GreenmailTestCase
{
    public function test_returns_the_raw_content_of_a_single_part_message(): void
    {
        $folderName = 'FetchBodyBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Simple\r\nContent-Type: text/plain\r\n\r\nJust one part\r\n"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame('Just one part', imap_fetchbody($connection, 1, '1'));
    }

    public function test_returns_the_raw_content_of_a_specific_mime_part(): void
    {
        $folderName = 'FetchBodyBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $message = "Subject: Multi\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"B1\"\r\n"
            ."\r\n"
            ."--B1\r\n"
            ."Content-Type: text/plain\r\n"
            ."\r\n"
            ."Hello body\r\n"
            ."--B1\r\n"
            ."Content-Type: application/octet-stream\r\n"
            ."\r\n"
            ."AAAA\r\n"
            ."--B1--\r\n";
        $seedClient->getFolder($folderName)->appendMessage($message);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame('Hello body', imap_fetchbody($connection, 1, '1'));
        $this->assertSame('AAAA', imap_fetchbody($connection, 1, '2'));
    }
}
