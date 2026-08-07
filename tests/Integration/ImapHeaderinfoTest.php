<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

class ImapHeaderinfoTest extends GreenmailTestCase
{
    /**
     * Asserts the Recent/Unseen flags, which are session-scoped and
     * differ between the two servers for the same message.
     */
    #[Group('greenmail-only')]
    public function test_returns_parsed_header_fields_and_flags(): void
    {
        $folderName = 'HeaderinfoBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Hello World\r\n"
            ."From: Joe Doe <joe@example.com>\r\n"
            ."To: jane@example.com\r\n"
            ."Date: Mon, 6 Jul 2026 12:00:00 +0000\r\n"
            ."\r\n"
            ."Body text"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $result = imap_headerinfo($connection, 1);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame('Hello World', $result->subject);
        $this->assertSame('Hello World', $result->Subject);
        $this->assertSame('joe', $result->from[0]->mailbox);
        $this->assertSame('example.com', $result->from[0]->host);
        $this->assertSame('Joe Doe', $result->from[0]->personal);
        $this->assertSame('Joe Doe <joe@example.com>', $result->fromaddress);
        $this->assertSame('jane', $result->to[0]->mailbox);
        $this->assertSame('example.com', $result->to[0]->host);
        $this->assertSame('jane@example.com', $result->toaddress);
        $this->assertSame('   1', $result->Msgno);
        $this->assertIsInt($result->udate);
        $this->assertIsString($result->Size);
        $this->assertSame(' ', $result->Recent);
        $this->assertSame('U', $result->Unseen);
        $this->assertSame(' ', $result->Flagged);
        $this->assertSame(' ', $result->Answered);
        $this->assertSame(' ', $result->Deleted);
        $this->assertSame(' ', $result->Draft);
    }

    public function test_returns_cc_bcc_reply_to_and_multiple_recipients(): void
    {
        $folderName = 'HeaderinfoBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Team Update\r\n"
            ."From: Joe Doe <joe@example.com>\r\n"
            ."To: jane@example.com, Bob Roe <bob@example.com>\r\n"
            ."Cc: carol@example.com\r\n"
            ."Bcc: dave@example.com\r\n"
            ."Reply-To: noreply@example.com\r\n"
            ."Date: Mon, 6 Jul 2026 12:00:00 +0000\r\n"
            ."\r\n"
            ."Body text"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $result = imap_headerinfo($connection, 1);

        $this->assertCount(2, $result->to);
        $this->assertSame('jane', $result->to[0]->mailbox);
        $this->assertSame('bob', $result->to[1]->mailbox);
        $this->assertSame('Bob Roe', $result->to[1]->personal);

        $this->assertSame('carol', $result->cc[0]->mailbox);
        $this->assertSame('carol@example.com', $result->ccaddress);
        $this->assertSame('dave', $result->bcc[0]->mailbox);
        $this->assertSame('dave@example.com', $result->bccaddress);
        $this->assertSame('noreply', $result->reply_to[0]->mailbox);
        $this->assertSame('noreply@example.com', $result->reply_toaddress);
    }

    public function test_omits_optional_address_fields_when_absent(): void
    {
        $folderName = 'HeaderinfoBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Minimal\r\nFrom: joe@example.com\r\nTo: jane@example.com\r\n\r\nBody"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $result = imap_headerinfo($connection, 1);

        $this->assertObjectNotHasProperty('cc', $result);
        $this->assertObjectNotHasProperty('bcc', $result);

        // RFC 5322: Reply-To and Sender default to From when absent.
        $this->assertSame('joe', $result->reply_to[0]->mailbox);
        $this->assertSame('joe@example.com', $result->reply_toaddress);
        $this->assertSame('joe', $result->sender[0]->mailbox);
        $this->assertSame('joe@example.com', $result->senderaddress);
    }

    public function test_fetchfrom_is_the_personal_name_padded_to_the_requested_width(): void
    {
        $folderName = 'HeaderinfoBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: A longer subject line\r\nFrom: Joe Doe <joe@example.com>\r\n\r\nBody"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $result = imap_headerinfo($connection, 1, 25, 10);

        $this->assertSame('Joe Doe                  ', $result->fetchfrom);
        $this->assertSame('A longer s', $result->fetchsubject);
    }

    public function test_fetchfrom_without_a_personal_name_uses_mailbox_at_host_and_truncates(): void
    {
        $folderName = 'HeaderinfoBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: S\r\nFrom: jane@example.com\r\n\r\nBody"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $result = imap_headerinfo($connection, 1, 20, 0);

        $this->assertSame('jane@example.com    ', $result->fetchfrom);
        $this->assertFalse(property_exists($result, 'fetchsubject'));

        // Truncated, not padded, when the address exceeds the width.
        $this->assertSame('jane@', imap_headerinfo($connection, 1, 5, 0)->fetchfrom);
    }

    public function test_fetchfrom_and_fetchsubject_are_absent_when_lengths_are_omitted(): void
    {
        $folderName = 'HeaderinfoBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: S\r\nFrom: jane@example.com\r\n\r\nBody"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $result = imap_headerinfo($connection, 1);

        $this->assertFalse(property_exists($result, 'fetchfrom'));
        $this->assertFalse(property_exists($result, 'fetchsubject'));
    }

    public function test_throws_value_error_for_an_out_of_range_from_length(): void
    {
        // The message number must be valid: ext-imap checks it (warning +
        // false when out of range) before it validates the lengths.
        $folderName = 'HeaderinfoValBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: S\r\n\r\nBody");
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_headerinfo(): Argument #3 ($from_length) must be between 0 and 1024');
        imap_headerinfo($connection, 1, -1);
    }

    public function test_throws_value_error_for_an_out_of_range_subject_length(): void
    {
        $folderName = 'HeaderinfoValBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: S\r\n\r\nBody");
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_headerinfo(): Argument #4 ($subject_length) must be between 0 and 1024');
        imap_headerinfo($connection, 1, 0, 1025);
    }

    public function test_throws_value_error_for_a_non_positive_message_number(): void
    {
        $folderName = 'HeaderinfoValBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_headerinfo(): Argument #2 ($message_num) must be greater than 0');
        imap_headerinfo($connection, 0);
    }

    /**
     * A message number past the end of the folder is not an error to
     * report: c-client answers it from the count it already holds, without
     * asking the server, so nothing reaches the error stack either.
     */
    public function test_returns_false_without_an_error_for_a_message_that_is_not_there(): void
    {
        $folderName = 'HeaderinfoEmptyBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());
        imap_errors();

        $this->assertFalse(imap_headerinfo($connection, 1));
        $this->assertFalse(imap_errors());
    }

    /**
     * The day of MailDate is space-padded, never zero-padded — c-client's
     * mail_date() normalizes it on the way out, whichever of the two forms
     * RFC 3501's date-day-fixed allows the server sent.
     */
    public function test_maildate_pads_a_single_digit_day_with_a_space(): void
    {
        $folderName = 'HeaderinfoDateBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());
        imap_append(
            $connection,
            self::mailboxSpec($folderName),
            "Subject: Dated\r\nFrom: joe@example.com\r\n\r\nBody",
            '\\Seen',
            '03-Jan-2012 09:30:03 +0000'
        );
        imap_reopen($connection, self::mailboxSpec($folderName));

        $result = imap_headerinfo($connection, 1);

        $this->assertSame(' 3-Jan-2012 09:30:03 +0000', $result->MailDate);
    }

    /**
     * A subject too long for one line arrives folded onto continuation
     * lines. c-client unfolds it and hands back the encoded words still
     * encoded, joined by the folding whitespace — decoding is
     * imap_utf8()/imap_mime_header_decode()'s job, not this one's.
     */
    public function test_a_folded_subject_is_unfolded_rather_than_dropped(): void
    {
        $folderName = 'HeaderinfoFoldBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $first = '=?UTF-8?B?'.base64_encode(str_repeat('A', 30)).'?=';
        $second = '=?UTF-8?B?'.base64_encode(str_repeat('B', 30)).'?=';
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: {$first}\r\n {$second}\r\n"
            ."From: joe@example.com\r\n"
            ."\r\n"
            ."Body"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $result = imap_headerinfo($connection, 1);

        $this->assertSame("{$first} {$second}", $result->subject);
        $this->assertSame("{$first} {$second}", $result->Subject);
        $this->assertSame(str_repeat('A', 30).str_repeat('B', 30), imap_utf8($result->subject));
    }
}
