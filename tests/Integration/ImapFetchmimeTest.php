<?php

namespace ImapPolyfill\Tests\Integration;

class ImapFetchmimeTest extends GreenmailTestCase
{
    private const MULTIPART_MESSAGE = "Subject: Multi\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: multipart/mixed; boundary=\"B1\"\r\n"
        ."\r\n"
        ."--B1\r\n"
        ."Content-Type: text/plain\r\n"
        ."\r\n"
        ."Hello body\r\n"
        ."--B1\r\n"
        ."Content-Type: application/octet-stream\r\n"
        ."Content-Transfer-Encoding: base64\r\n"
        ."\r\n"
        ."AAAA\r\n"
        ."--B1--\r\n";

    public function test_returns_the_mime_headers_of_a_specific_part(): void
    {
        $folderName = 'FetchMimeBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(self::MULTIPART_MESSAGE);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $mime = imap_fetchmime($connection, 1, '2');

        $this->assertIsString($mime);
        $this->assertStringContainsString('Content-Type: application/octet-stream', $mime);
        $this->assertStringContainsString('Content-Transfer-Encoding: base64', $mime);
    }

    public function test_ft_peek_leaves_the_message_unseen(): void
    {
        $folderName = 'FetchMimeBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(self::MULTIPART_MESSAGE);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        imap_fetchmime($connection, 1, '1', FT_PEEK);

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(0, $overview[0]->seen);
    }

    public function test_fetching_without_ft_peek_marks_the_message_seen(): void
    {
        $folderName = 'FetchMimeBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(self::MULTIPART_MESSAGE);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        imap_fetchmime($connection, 1, '1');

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(1, $overview[0]->seen);
    }

    public function test_ft_uid_fetches_by_uid_when_it_diverges_from_msgno(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'FetchMimeUidBox'.uniqid(),
            self::MULTIPART_MESSAGE
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $byMsgno = imap_fetchmime($connection, 1, '1');
        $byUid = imap_fetchmime($connection, $survivorUid, '1', FT_UID);

        $this->assertSame($byMsgno, $byUid);
        $this->assertStringContainsString('Content-Type: text/plain', $byMsgno);
    }

    public function test_throws_value_error_for_a_non_positive_message_number(): void
    {
        $folderName = 'FetchMimeValBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_fetchmime(): Argument #2 ($message_num) must be greater than 0');
        imap_fetchmime($connection, 0, '1');
    }

    public function test_throws_value_error_for_an_invalid_flags_bitmask(): void
    {
        $folderName = 'FetchMimeValBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(self::MULTIPART_MESSAGE);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_fetchmime(): Argument #4 ($flags) must be a bitmask of FT_UID, FT_PEEK, and FT_INTERNAL');
        imap_fetchmime($connection, 1, '1', 0x40);
    }

    public function test_returns_false_when_the_connection_is_broken(): void
    {
        $folderName = 'FetchMimeGoneBox'.uniqid();
        $connection = $this->openConnectionToFolderThatThenDisappears($folderName);

        $this->assertFalse(imap_fetchmime($connection, 1, '1'));
    }
}
