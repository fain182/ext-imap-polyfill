<?php

namespace ImapPolyfill\Tests\Integration;

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

    public function test_returns_structure_of_a_nested_multipart(): void
    {
        $folderName = 'StructBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $message = "Subject: Nested\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"B1\"\r\n"
            ."\r\n"
            ."--B1\r\n"
            ."Content-Type: multipart/alternative; boundary=\"B2\"\r\n"
            ."\r\n"
            ."--B2\r\n"
            ."Content-Type: text/plain\r\n"
            ."\r\n"
            ."Plain alt\r\n"
            ."--B2\r\n"
            ."Content-Type: text/html\r\n"
            ."\r\n"
            ."<b>Html alt</b>\r\n"
            ."--B2--\r\n"
            ."--B1\r\n"
            ."Content-Type: application/octet-stream\r\n"
            ."\r\n"
            ."AAAA\r\n"
            ."--B1--\r\n";
        $seedClient->getFolder($folderName)->appendMessage($message);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_fetchstructure($connection, 1);

        $this->assertSame(1, $result->type); // TYPEMULTIPART
        $this->assertSame('MIXED', $result->subtype);
        $this->assertCount(2, $result->parts);

        $nestedAlternative = $result->parts[0];
        $this->assertSame(1, $nestedAlternative->type); // TYPEMULTIPART
        $this->assertSame('ALTERNATIVE', $nestedAlternative->subtype);
        $this->assertCount(2, $nestedAlternative->parts);
        $this->assertSame(0, $nestedAlternative->parts[0]->type); // TYPETEXT
        $this->assertSame('PLAIN', $nestedAlternative->parts[0]->subtype);
        $this->assertSame('HTML', $nestedAlternative->parts[1]->subtype);

        $this->assertSame(3, $result->parts[1]->type); // TYPEAPPLICATION
    }

    public function test_ft_uid_fetches_by_uid_when_it_diverges_from_msgno(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'StructUidBox' . uniqid(),
            "Subject: Survivor\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\nBody"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $byMsgno = imap_fetchstructure($connection, 1);
        $byUid = imap_fetchstructure($connection, $survivorUid, FT_UID);

        $this->assertSame('PLAIN', $byMsgno->subtype);
        $this->assertEquals($byMsgno, $byUid);
    }

    public function test_throws_value_error_for_a_non_positive_message_number(): void
    {
        $folderName = 'StructValBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_fetchstructure(): Argument #2 ($message_num) must be greater than 0');
        imap_fetchstructure($connection, 0);
    }
}
