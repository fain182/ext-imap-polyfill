<?php

namespace ImapPolyfill\Tests\Integration;

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

    public function test_section_zero_returns_the_header(): void
    {
        $folderName = 'FetchBodyBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hi\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame("Subject: Hi\r\n", imap_fetchbody($connection, 1, '0'));
    }

    public function test_nested_subpart_section(): void
    {
        $folderName = 'FetchBodyBox' . uniqid();
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

        $this->assertSame('Plain alt', imap_fetchbody($connection, 1, '1.1'));
        $this->assertSame('<b>Html alt</b>', imap_fetchbody($connection, 1, '1.2'));
        $this->assertSame('AAAA', imap_fetchbody($connection, 1, '2'));
    }

    public function test_an_empty_section_returns_the_entire_message(): void
    {
        $folderName = 'FetchBodyBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Whole\r\nContent-Type: text/plain\r\n\r\nEntire body\r\n"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $whole = imap_fetchbody($connection, 1, '');

        $this->assertIsString($whole);
        $this->assertStringContainsString('Subject: Whole', $whole);
        $this->assertStringContainsString('Entire body', $whole);
    }

    public function test_ft_peek_leaves_the_message_unseen(): void
    {
        $folderName = 'FetchBodyBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Peek\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        imap_fetchbody($connection, 1, '1', FT_PEEK);

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(0, $overview[0]->seen);
    }

    public function test_fetching_without_ft_peek_marks_the_message_seen(): void
    {
        $folderName = 'FetchBodyBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: NoPeek\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        imap_fetchbody($connection, 1, '1');

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(1, $overview[0]->seen);
    }

    public function test_ft_uid_fetches_by_uid_when_it_diverges_from_msgno(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'FetchBodyUidBox' . uniqid(),
            "Subject: Survivor\r\nContent-Type: text/plain\r\n\r\nSurvivor body"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $byMsgno = imap_fetchbody($connection, 1, '1');
        $byUid = imap_fetchbody($connection, $survivorUid, '1', FT_UID);

        $this->assertSame('Survivor body', $byMsgno);
        $this->assertSame($byMsgno, $byUid);
    }

    public function test_throws_value_error_for_a_non_positive_message_number(): void
    {
        $folderName = 'FetchBodyValBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_fetchbody(): Argument #2 ($message_num) must be greater than 0');
        imap_fetchbody($connection, 0, '1');
    }
}
