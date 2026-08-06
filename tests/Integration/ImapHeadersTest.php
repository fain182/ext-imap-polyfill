<?php

namespace ImapPolyfill\Tests\Integration;

final class ImapHeadersTest extends GreenmailTestCase
{
    public function test_headers_format_matches_real_ext_imap(): void
    {
        $folderName = self::randomFolderName(__FUNCTION__);
        $client = $this->makeFolder($folderName);
        $message = "From: Alice Smith <alice@example.com>\r\nTo: bob@example.com\r\nSubject: First message\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nBody 1";
        $client->getFolder($folderName)->appendMessage($message);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $headers = imap_headers($connection);

        // The date column comes from INTERNALDATE (server-assigned at
        // APPEND time), not the message's own Date: header — so it tracks
        // whatever "today" is when this test runs, not the fixed Date:
        // above. The size is RFC822.SIZE, i.e. the whole message as
        // appended.
        $this->assertSame(' U       1)'.self::todayField().' Alice Smith          First message ('.strlen($message).' chars)', $headers[0]);

        imap_close($connection);
    }

    public function test_headers_truncates_subject_and_pads_from(): void
    {
        $folderName = self::randomFolderName(__FUNCTION__);
        $client = $this->makeFolder($folderName);
        $message = "From: carol@example.com\r\nTo: bob@example.com\r\nSubject: This subject is definitely longer than twenty five characters\r\nDate: Tue, 07 Jul 2026 08:00:00 +0000\r\n\r\nBody";
        $client->getFolder($folderName)->appendMessage($message);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $headers = imap_headers($connection);

        $this->assertSame(' U       1)'.self::todayField().' carol@example.com    This subject is definitel ('.strlen($message).' chars)', $headers[0]);

        imap_close($connection);
    }

    /**
     * c-client's mail_date() format: space-padded day, e.g. " 7-Jul-2026".
     */
    private static function todayField(): string
    {
        return sprintf('%2d-%s-%s', (int) gmdate('j'), gmdate('M'), gmdate('Y'));
    }

    public function test_headers_omits_keywords_the_server_leaves_out_of_the_flags_response(): void
    {
        // c-client fills its session flag table from the untagged FLAGS
        // list alone (imap4r1.c: PERMANENTFLAGS only looks names up, never
        // adds them), and renders the "{flag}" segment only for keywords in
        // that table. GreenMail lists custom keywords in PERMANENTFLAGS —
        // since 2.1.11 — but never in FLAGS, so keywords stay invisible
        // here whether another session stored them (OtherSession) or this
        // very session did (KeyA/KeyB). Positive rendering is covered in
        // tests/Unit/HeadersLineTest, since no scenario against GreenMail
        // can produce it.
        $folderName = self::randomFolderName(__FUNCTION__);
        $client = $this->makeFolder($folderName);
        $message = "From: Alice Smith <alice@example.com>\r\nTo: bob@example.com\r\nSubject: First message\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nBody 1";
        $client->getFolder($folderName)->appendMessage($message);
        $client->openFolder($folderName);
        $client->command('STORE', ['1', '+FLAGS.SILENT', '(OtherSession)']);

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);
        imap_setflag_full($connection, '1', 'KeyA KeyB');

        $headers = imap_headers($connection);

        $this->assertSame(' U       1)'.self::todayField().' Alice Smith          First message ('.strlen($message).' chars)', $headers[0]);

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
