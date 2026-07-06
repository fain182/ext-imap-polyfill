<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapHeaderinfoTest extends GreenmailTestCase
{
    public function test_returns_parsed_header_fields_and_flags(): void
    {
        $folderName = 'HeaderinfoBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Hello World\r\n"
            ."From: Joe Doe <joe@example.com>\r\n"
            ."To: jane@example.com\r\n"
            ."Date: Mon, 6 Jul 2026 12:00:00 +0000\r\n"
            ."\r\n"
            ."Body text"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_headerinfo($connection, 1);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame('Hello World', $result->subject);
        $this->assertSame('Hello World', $result->Subject);
        $this->assertSame('joe', $result->from[0]->mailbox);
        $this->assertSame('example.com', $result->from[0]->host);
        $this->assertSame('Joe Doe', $result->from[0]->personal);
        $this->assertSame('Joe Doe <joe@example.com>', $result->fromaddress);
        $this->assertSame('jane', $result->to[0]->mailbox);
        $this->assertSame('example.com', $result->to[0]->host);
        $this->assertSame('jane@example.com', $result->toaddress);
        $this->assertSame('   1', $result->Msgno);
        $this->assertIsInt($result->udate);
        $this->assertIsString($result->Size);
        $this->assertSame(' ', $result->Recent);
        $this->assertSame('U', $result->Unseen);
        $this->assertSame(' ', $result->Flagged);
        $this->assertSame(' ', $result->Answered);
        $this->assertSame(' ', $result->Deleted);
        $this->assertSame(' ', $result->Draft);
    }
}
