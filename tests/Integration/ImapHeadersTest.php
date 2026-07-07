<?php

namespace ImapPolyfill\Tests\Integration;

final class ImapHeadersTest extends GreenmailTestCase
{
    public function test_headers_format_matches_real_ext_imap(): void
    {
        $folderName = self::randomFolderName(__FUNCTION__);
        $client = $this->makeFolder($folderName);
        $client->getFolder($folderName)->appendMessage(
            "From: Alice Smith <alice@example.com>\r\nTo: bob@example.com\r\nSubject: First message\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nBody 1"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $headers = imap_headers($connection);

        $this->assertSame(' U       1) 7-Jul-2026 Alice Smith          First message (6 chars)', $headers[0]);

        imap_close($connection);
    }

    public function test_headers_truncates_subject_and_pads_from(): void
    {
        $folderName = self::randomFolderName(__FUNCTION__);
        $client = $this->makeFolder($folderName);
        $client->getFolder($folderName)->appendMessage(
            "From: carol@example.com\r\nTo: bob@example.com\r\nSubject: This subject is definitely longer than twenty five characters\r\nDate: Tue, 07 Jul 2026 08:00:00 +0000\r\n\r\nBody"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $headers = imap_headers($connection);

        $this->assertSame(' U       1) 7-Jul-2026 carol@example.com    This subject is definitel (4 chars)', $headers[0]);

        imap_close($connection);
    }

    public function test_headers_empty_mailbox_returns_empty_array(): void
    {
        $folderName = self::randomFolderName(__FUNCTION__);
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertSame([], imap_headers($connection));

        imap_close($connection);
    }

    private static function randomFolderName(string $name): string
    {
        return $name.random_int(10000, 99999);
    }
}
