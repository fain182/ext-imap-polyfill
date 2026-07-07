<?php

namespace ImapPolyfill\Tests\Integration;

class ImapSavebodyTest extends GreenmailTestCase
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
        ."\r\n"
        ."AAAA\r\n"
        ."--B1--\r\n";

    public function test_saves_a_specific_part_to_a_file_path(): void
    {
        $folderName = 'SaveBodyBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(self::MULTIPART_MESSAGE);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $path = tempnam(sys_get_temp_dir(), 'imap_savebody_');

        try {
            $this->assertTrue(imap_savebody($connection, $path, 1, '1'));
            $this->assertSame('Hello body', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_saves_a_specific_part_to_an_open_stream(): void
    {
        $folderName = 'SaveBodyBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(self::MULTIPART_MESSAGE);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $stream = fopen('php://memory', 'w+b');

        $this->assertTrue(imap_savebody($connection, $stream, 1, '2'));

        rewind($stream);
        $this->assertSame('AAAA', stream_get_contents($stream));
        fclose($stream);
    }

    public function test_empty_section_saves_the_entire_message(): void
    {
        $folderName = 'SaveBodyBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Whole\r\nContent-Type: text/plain\r\n\r\nEntire body\r\n"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $path = tempnam(sys_get_temp_dir(), 'imap_savebody_');

        try {
            $this->assertTrue(imap_savebody($connection, $path, 1));

            $saved = file_get_contents($path);
            $this->assertStringContainsString('Subject: Whole', $saved);
            $this->assertStringContainsString('Entire body', $saved);
        } finally {
            @unlink($path);
        }
    }

    public function test_section_zero_saves_only_the_header(): void
    {
        $folderName = 'SaveBodyBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hi\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $path = tempnam(sys_get_temp_dir(), 'imap_savebody_');

        try {
            $this->assertTrue(imap_savebody($connection, $path, 1, '0'));
            $this->assertSame("Subject: Hi\r\n", file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_ft_uid_saves_by_uid_when_it_diverges_from_msgno(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'SaveBodyUidBox'.uniqid(),
            "Subject: Survivor\r\nContent-Type: text/plain\r\n\r\nSurvivor body"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $path = tempnam(sys_get_temp_dir(), 'imap_savebody_');

        try {
            $this->assertTrue(imap_savebody($connection, $path, $survivorUid, '1', FT_UID));
            $this->assertSame('Survivor body', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_returns_false_for_an_unwritable_path(): void
    {
        $folderName = 'SaveBodyBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(self::MULTIPART_MESSAGE);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertFalse(@imap_savebody($connection, '/nonexistent-dir/imap_savebody_test', 1, '1'));
    }

    public function test_throws_value_error_for_a_non_positive_message_number(): void
    {
        $folderName = 'SaveBodyValBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_savebody(): Argument #3 ($message_num) must be greater than 0');
        imap_savebody($connection, tempnam(sys_get_temp_dir(), 'imap_savebody_'), 0, '1');
    }

    public function test_throws_value_error_for_an_invalid_flags_bitmask(): void
    {
        $folderName = 'SaveBodyValBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(self::MULTIPART_MESSAGE);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_savebody(): Argument #5 ($flags) must be a bitmask of FT_UID, FT_PEEK, and FT_INTERNAL');
        imap_savebody($connection, tempnam(sys_get_temp_dir(), 'imap_savebody_'), 1, '1', 0x40);
    }
}
