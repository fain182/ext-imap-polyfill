<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\CapturesWarnings;

class ImapFetchheaderTest extends GreenmailTestCase
{
    use CapturesWarnings;

    public function test_returns_the_raw_header_of_a_message(): void
    {
        $folderName = 'FetchHeaderBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello World\r\n\r\nBody text");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

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

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $byMsgno = imap_fetchheader($connection, 1);
        $byUid = imap_fetchheader($connection, $survivorUid, FT_UID);

        $this->assertStringContainsString('Subject: Survivor', $byMsgno);
        $this->assertSame($byMsgno, $byUid);
    }

    public function test_throws_value_error_for_a_non_positive_message_number(): void
    {
        $folderName = 'FetchHeaderValBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_fetchheader(): Argument #2 ($message_num) must be greater than 0');
        imap_fetchheader($connection, 0);
    }

    public function test_throws_value_error_for_an_invalid_flags_bitmask(): void
    {
        $folderName = 'FetchHeaderValBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Flags\r\n\r\nBody");
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_fetchheader(): Argument #3 ($flags) must be a bitmask of FT_UID, FT_PREFETCHTEXT, and FT_INTERNAL');
        imap_fetchheader($connection, 1, FT_PEEK);
    }

    /**
     * FT_PREFETCHTEXT only tells c-client to fetch the body along with the
     * header, so the header comes back unchanged — the point is that the
     * flag is in this function's bitmask and no other's.
     */
    public function test_accepts_ft_prefetchtext(): void
    {
        $folderName = 'FetchHeaderPrefetchBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Flags\r\n\r\nBody");
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame(
            imap_fetchheader($connection, 1),
            imap_fetchheader($connection, 1, FT_PREFETCHTEXT)
        );
    }

    /**
     * c-client answers a message number past the end of the folder from the
     * count it already holds: a warning, false, and nothing said to the
     * server or left on the error stack.
     */
    public function test_warns_for_a_message_number_past_the_end(): void
    {
        $folderName = 'FetchHeaderWarnBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Present\r\n\r\nBody");
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        [$result, $warnings] = $this->capturingWarnings(fn () => imap_fetchheader($connection, 9));

        $this->assertFalse($result);
        $this->assertSame(['imap_fetchheader(): Bad message number'], $warnings);
    }

    public function test_warns_for_a_uid_that_does_not_exist(): void
    {
        $folderName = 'FetchHeaderWarnBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Present\r\n\r\nBody");
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        [$result, $warnings] = $this->capturingWarnings(fn () => imap_fetchheader($connection, 99999, FT_UID));

        $this->assertFalse($result);
        $this->assertSame(['imap_fetchheader(): UID does not exist'], $warnings);
    }
}
