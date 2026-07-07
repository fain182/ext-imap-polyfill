<?php

namespace ImapPolyfill\Tests\Integration;

class ImapBodystructTest extends GreenmailTestCase
{
    public function test_returns_structure_of_the_only_part_in_a_single_part_message(): void
    {
        $folderName = 'BodystructBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Hello\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\nHello body\r\n"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_bodystruct($connection, 1, '1');

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame(0, $result->type); // TYPETEXT
        $this->assertSame('PLAIN', $result->subtype);
        $this->assertSame('us-ascii', $result->parameters[0]->value);
    }

    public function test_returns_structure_of_a_specific_part_in_a_multipart_message(): void
    {
        $folderName = 'BodystructBox'.uniqid();
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
            ."Content-Type: application/octet-stream; name=\"test.bin\"\r\n"
            ."Content-Disposition: attachment; filename=\"test.bin\"\r\n"
            ."\r\n"
            ."AAAA\r\n"
            ."--B1--\r\n";
        $seedClient->getFolder($folderName)->appendMessage($message);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $part1 = imap_bodystruct($connection, 1, '1');
        $part2 = imap_bodystruct($connection, 1, '2');

        $this->assertSame(0, $part1->type); // TYPETEXT
        $this->assertSame(3, $part2->type); // TYPEAPPLICATION
        $this->assertSame(1, $part2->ifdisposition);
        $this->assertSame('attachment', $part2->disposition);
        $this->assertSame('test.bin', $part2->dparameters[0]->value);
    }

    public function test_returns_structure_of_a_nested_subpart(): void
    {
        $folderName = 'BodystructBox'.uniqid();
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

        $htmlAlt = imap_bodystruct($connection, 1, '1.2');

        $this->assertSame(0, $htmlAlt->type); // TYPETEXT
        $this->assertSame('HTML', $htmlAlt->subtype);
    }

    public function test_matches_the_corresponding_part_of_fetchstructure(): void
    {
        $folderName = 'BodystructBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $message = "Subject: Multi\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"B1\"\r\n"
            ."\r\n"
            ."--B1\r\n"
            ."Content-Type: text/plain; charset=us-ascii\r\n"
            ."\r\n"
            ."Hello body\r\n"
            ."--B1\r\n"
            ."Content-Type: application/octet-stream\r\n"
            ."\r\n"
            ."AAAA\r\n"
            ."--B1--\r\n";
        $seedClient->getFolder($folderName)->appendMessage($message);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $structure = imap_fetchstructure($connection, 1);
        $part1 = imap_bodystruct($connection, 1, '1');

        $this->assertEquals($structure->parts[0], $part1);
    }

    public function test_returns_false_for_a_section_that_does_not_exist(): void
    {
        $folderName = 'BodystructBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hi\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertFalse(imap_bodystruct($connection, 1, '2'));
    }

    public function test_throws_value_error_for_a_non_positive_message_number(): void
    {
        $folderName = 'BodystructValBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_bodystruct(): Argument #2 ($message_num) must be greater than 0');
        imap_bodystruct($connection, 0, '1');
    }
}
