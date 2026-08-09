<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\CapturesWarnings;

class ImapBodyTest extends GreenmailTestCase
{
    use CapturesWarnings;

    public function test_returns_the_body_without_the_header(): void
    {
        $folderName = 'BodyBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Hello\r\nContent-Type: text/plain\r\n\r\nJust the body\r\n"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $body = imap_body($connection, 1);

        $this->assertIsString($body);
        $this->assertStringContainsString('Just the body', $body);
        $this->assertStringNotContainsString('Subject: Hello', $body);
    }

    public function test_imap_fetchtext_is_an_alias_of_imap_body(): void
    {
        $folderName = 'BodyBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Alias\r\n\r\nSame content\r\n");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame(imap_body($connection, 1), imap_fetchtext($connection, 1));
    }

    public function test_ft_peek_leaves_the_message_unseen(): void
    {
        $folderName = 'BodyBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Peek\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        imap_body($connection, 1, FT_PEEK);

        $overview = imap_fetch_overview($connection, '1:1');
        $this->assertSame(0, $overview[0]->seen);
    }

    public function test_throws_value_error_for_a_non_positive_message_number(): void
    {
        $folderName = 'BodyBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->expectException(\ValueError::class);
        imap_body($connection, 0);
    }

    public function test_rejects_flags_outside_ft_uid_ft_peek_ft_internal(): void
    {
        $folderName = 'BodyBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Hello\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->expectException(\ValueError::class);
        imap_body($connection, 1, 4096);
    }

    /**
     * c-client answers a message number past the end of the folder from the
     * count it already holds: a warning, false, and nothing said to the
     * server or left on the error stack.
     */
    public function test_warns_for_a_message_number_past_the_end(): void
    {
        $folderName = 'BodyWarnBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Present\r\n\r\nBody");
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        [$result, $warnings] = $this->capturingWarnings(fn () => imap_body($connection, 9));

        $this->assertFalse($result);
        $this->assertSame(['imap_body(): Bad message number'], $warnings);
    }

    public function test_warns_for_a_uid_that_does_not_exist(): void
    {
        $folderName = 'BodyWarnBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Present\r\n\r\nBody");
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        [$result, $warnings] = $this->capturingWarnings(fn () => imap_body($connection, 99999, FT_UID));

        $this->assertFalse($result);
        $this->assertSame(['imap_body(): UID does not exist'], $warnings);
    }
}
