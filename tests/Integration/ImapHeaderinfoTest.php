<?php

namespace ImapPolyfill\Tests\Integration;

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

    public function test_returns_cc_bcc_reply_to_and_multiple_recipients(): void
    {
        $folderName = 'HeaderinfoBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Team Update\r\n"
            ."From: Joe Doe <joe@example.com>\r\n"
            ."To: jane@example.com, Bob Roe <bob@example.com>\r\n"
            ."Cc: carol@example.com\r\n"
            ."Bcc: dave@example.com\r\n"
            ."Reply-To: noreply@example.com\r\n"
            ."Date: Mon, 6 Jul 2026 12:00:00 +0000\r\n"
            ."\r\n"
            ."Body text"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_headerinfo($connection, 1);

        $this->assertCount(2, $result->to);
        $this->assertSame('jane', $result->to[0]->mailbox);
        $this->assertSame('bob', $result->to[1]->mailbox);
        $this->assertSame('Bob Roe', $result->to[1]->personal);

        $this->assertSame('carol', $result->cc[0]->mailbox);
        $this->assertSame('carol@example.com', $result->ccaddress);
        $this->assertSame('dave', $result->bcc[0]->mailbox);
        $this->assertSame('dave@example.com', $result->bccaddress);
        $this->assertSame('noreply', $result->reply_to[0]->mailbox);
        $this->assertSame('noreply@example.com', $result->reply_toaddress);
    }

    public function test_omits_optional_address_fields_when_absent(): void
    {
        $folderName = 'HeaderinfoBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Minimal\r\nFrom: joe@example.com\r\nTo: jane@example.com\r\n\r\nBody"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_headerinfo($connection, 1);

        $this->assertObjectNotHasProperty('cc', $result);
        $this->assertObjectNotHasProperty('bcc', $result);

        // RFC 5322: Reply-To and Sender default to From when absent.
        $this->assertSame('joe', $result->reply_to[0]->mailbox);
        $this->assertSame('joe@example.com', $result->reply_toaddress);
        $this->assertSame('joe', $result->sender[0]->mailbox);
        $this->assertSame('joe@example.com', $result->senderaddress);
    }
}
