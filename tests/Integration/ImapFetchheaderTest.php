<?php

namespace ImapPolyfill\Tests\Integration;

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

    public function test_ft_uid_fetches_by_uid_when_it_diverges_from_msgno(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'FetchHeaderUidBox' . uniqid(),
            "Subject: Survivor\r\n\r\nKeep me"
        );
        $this->assertGreaterThan(1, $survivorUid, 'fixture must produce uid != msgno to be a meaningful test');

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $byMsgno = imap_fetchheader($connection, 1);
        $byUid = imap_fetchheader($connection, $survivorUid, FT_UID);

        $this->assertStringContainsString('Subject: Survivor', $byMsgno);
        $this->assertSame($byMsgno, $byUid);
    }

    public function test_throws_value_error_for_a_non_positive_message_number(): void
    {
        $folderName = 'FetchHeaderValBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_fetchheader(): Argument #2 ($message_num) must be greater than 0');
        imap_fetchheader($connection, 0);
    }
}
