<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapFetchstructureTest extends GreenmailTestCase
{
    public function test_returns_structure_of_a_single_part_message(): void
    {
        $folderName = 'StructBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Hello\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\nHello body\r\n"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_fetchstructure($connection, 1);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame(0, $result->type); // TYPETEXT
        $this->assertSame('PLAIN', $result->subtype);
        $this->assertSame(0, $result->encoding); // ENC7BIT
        $this->assertSame('us-ascii', $result->parameters[0]->value);
    }

    public function test_returns_structure_of_a_multipart_message_with_attachment(): void
    {
        $folderName = 'StructBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $message = "Subject: Multi\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"BOUND1\"\r\n"
            ."\r\n"
            ."--BOUND1\r\n"
            ."Content-Type: text/plain; charset=us-ascii\r\n"
            ."\r\n"
            ."Hello body\r\n"
            ."--BOUND1\r\n"
            ."Content-Type: application/octet-stream; name=\"test.bin\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n"
            ."Content-Disposition: attachment; filename=\"test.bin\"\r\n"
            ."\r\n"
            ."AAAA\r\n"
            ."--BOUND1--\r\n";
        $seedClient->getFolder($folderName)->appendMessage($message);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_fetchstructure($connection, 1);

        $this->assertSame(1, $result->type); // TYPEMULTIPART
        $this->assertSame('MIXED', $result->subtype);
        $this->assertCount(2, $result->parts);
        $this->assertSame(0, $result->parts[0]->type);
        $this->assertSame(3, $result->parts[1]->type); // TYPEAPPLICATION
        $this->assertSame(1, $result->parts[1]->ifdisposition);
        $this->assertSame('attachment', $result->parts[1]->disposition);
        $this->assertSame('test.bin', $result->parts[1]->dparameters[0]->value);
    }
}
