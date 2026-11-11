<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\CapturesWarnings;
use PHPUnit\Framework\Attributes\Group;

/**
 * POP3 message reading, flags/delete, search, and structure. Greenmail's
 * POP3 service only ever exposes a single shared INBOX (see
 * Pop3OpenTest::currentCount() for why assertions use relative counts/UIDs
 * rather than absolute msgnos where the shared fixture matters).
 */
final class Pop3MailboxTest extends GreenmailTestCase
{
    use CapturesWarnings;

    public function test_headerinfo_body_and_fetchbody(): void
    {
        $client = self::seedClient();
        $folder = $client->getFolder('INBOX');
        $folder->appendMessage(
            "From: alice@example.com\r\nTo: bob@example.com\r\nSubject: Hello\r\n\r\nHello body\r\n"
        );

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $header = imap_headerinfo($connection, $count);
        $this->assertSame('Hello', $header->subject);
        $this->assertSame('alice@example.com', $header->from[0]->mailbox.'@'.$header->from[0]->host);

        $this->assertSame("Hello body\r\n", imap_body($connection, $count));
        $this->assertSame("Hello body\r\n", imap_fetchbody($connection, $count, '1'));

        imap_close($connection);
    }

    public function test_fetch_overview(): void
    {
        $client = self::seedClient();
        $folder = $client->getFolder('INBOX');
        $folder->appendMessage("Subject: Overview Me\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $overview = imap_fetch_overview($connection, (string) $count);

        $this->assertCount(1, $overview);
        $this->assertSame('Overview Me', $overview[0]->subject);
        $this->assertSame($count, $overview[0]->msgno);

        imap_close($connection);
    }

    /**
     * POP3 has no SORT command, so the ordering is computed locally, by RFC
     * 5256 base subjects: "Re: Zulu" sorts on "zulu" and lands ahead of
     * "Zulus". This is the only place the local sort still runs against a
     * live server — over IMAP it is the server's job (ImapSortTest).
     * Asserted on the two seeded messages only: GreenMail's POP3 INBOX is
     * shared by every test in this class.
     */
    public function test_sort_orders_locally_when_the_protocol_has_no_sort(): void
    {
        $folder = self::seedClient()->getFolder('INBOX');
        $folder->appendMessage("Subject: Zulus\r\n\r\nBody");
        $folder->appendMessage("Subject: Re: Zulu\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);
        $sorted = imap_sort($connection, SORTSUBJECT, false);

        $positions = array_flip($sorted);
        $this->assertLessThan($positions[$count - 1], $positions[$count]);

        imap_close($connection);
    }

    /**
     * c-client's POP3 driver numbers messages 1..n and uses that number as
     * the uid, ignoring the server's UIDL string entirely.
     *
     * Greenmail answers UIDL with "1 1", "2 2", so a uid taken from the UIDL
     * looks identical here and this assertion cannot fail against it. It
     * fails against a server whose UIDL is an opaque token — Dovecot's
     * "000000016a759b25" casts to 16 — which is what `make cross-check`
     * is for.
     */
    public function test_uid_is_the_message_number(): void
    {
        self::seedClient()->getFolder('INBOX')->appendMessage("Subject: Uid Rule\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $this->assertSame($count, imap_uid($connection, $count));
        $this->assertSame($count, imap_msgno($connection, $count));

        imap_close($connection);
    }

    public function test_uid_stable_across_reconnect(): void
    {
        $client = self::seedClient();
        $folder = $client->getFolder('INBOX');
        $folder->appendMessage("Subject: Uid Me\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);
        $uid = imap_uid($connection, $count);
        imap_close($connection);

        $reconnected = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $this->assertSame($uid, imap_uid($reconnected, $count));
        $this->assertSame($count, imap_msgno($reconnected, $uid));
        imap_close($reconnected);
    }

    public function test_search_all_and_subject(): void
    {
        $client = self::seedClient();
        $folder = $client->getFolder('INBOX');
        $marker = 'Uniq'.random_int(10000, 99999);
        $folder->appendMessage("Subject: {$marker}\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $all = imap_search($connection, 'ALL');
        $this->assertIsArray($all);
        $this->assertContains($count, $all);

        $bySubject = imap_search($connection, 'SUBJECT '.$marker);
        $this->assertSame([$count], $bySubject);

        imap_close($connection);
    }

    public function test_search_matches_header_terms_against_the_decoded_header(): void
    {
        $marker = 'Pop3Mime'.random_int(10000, 99999);
        self::seedClient()->getFolder('INBOX')->appendMessage(
            "From: =?UTF-8?Q?Jos=C3=A9_{$marker}?= <jose@example.com>\r\n"
            ."Subject: =?UTF-8?B?".base64_encode("Caf\u{e9} {$marker}")."?=\r\n"
            ."\r\n"
            ."Body\r\n"
        );
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $this->assertSame([$count], imap_search($connection, "SUBJECT \"Caf\u{e9} {$marker}\""));
        $this->assertSame([$count], imap_search($connection, "SUBJECT \"caf\u{e9} ".strtolower($marker).'"'));
        $this->assertSame([$count], imap_search($connection, "FROM \"Jos\u{e9} {$marker}\""));
        $this->assertSame([$count], imap_search($connection, "TEXT \"Caf\u{e9} {$marker}\""));
        $this->assertFalse(imap_search($connection, 'SUBJECT "=?UTF-8?B?"'.' FROM '.$marker));

        imap_close($connection);
    }

    public function test_search_body_reads_the_decoded_text_parts(): void
    {
        $marker = 'Pop3Body'.random_int(10000, 99999);
        self::seedClient()->getFolder('INBOX')->appendMessage(
            "MIME-Version: 1.0\r\n"
            ."Subject: {$marker}\r\n"
            ."Content-Type: multipart/mixed; boundary=\"b\"\r\n"
            ."\r\n"
            ."--b\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n"
            ."Content-Transfer-Encoding: base64\r\n"
            ."Content-Description: Desc{$marker}\r\n"
            ."\r\n"
            .base64_encode("Z\u{fc}rich{$marker}")."\r\n"
            ."--b\r\n"
            ."Content-Type: text/plain; charset=ISO-8859-1\r\n"
            ."Content-Transfer-Encoding: quoted-printable\r\n"
            ."\r\n"
            ."caf=E9 Qp{$marker}\r\n"
            ."--b\r\n"
            ."Content-Type: text/plain\r\n"
            ."Content-Transfer-Encoding: 8bit\r\n"
            ."\r\n"
            ."Stra\u{df}e{$marker}\r\n"
            ."--b\r\n"
            ."Content-Type: application/octet-stream\r\n"
            ."Content-Transfer-Encoding: base64\r\n"
            ."\r\n"
            .base64_encode("Hidden{$marker}")."\r\n"
            ."--b\r\n"
            ."Content-Type: message/rfc822\r\n"
            ."\r\n"
            ."Subject: Nested{$marker}\r\n"
            ."\r\n"
            ."Inner{$marker}\r\n"
            ."--b--\r\n"
        );
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $this->assertSame([$count], imap_search($connection, "BODY \"Z\u{fc}rich{$marker}\""));
        $this->assertSame([$count], imap_search($connection, "BODY \"caf\u{e9} Qp{$marker}\""));
        $this->assertSame([$count], imap_search($connection, "BODY \"Stra\u{df}e{$marker}\""));
        $this->assertSame([$count], imap_search($connection, "BODY Inner{$marker}"));
        $this->assertFalse(imap_search($connection, "BODY Hidden{$marker}"));
        $this->assertFalse(imap_search($connection, "BODY Desc{$marker}"));
        $this->assertFalse(imap_search($connection, "BODY Nested{$marker}"));
        $this->assertSame([$count], imap_search($connection, "TEXT Desc{$marker}"));
        $this->assertSame([$count], imap_search($connection, "TEXT Nested{$marker}"));
        $this->assertFalse(imap_search($connection, "TEXT Hidden{$marker}"));

        imap_close($connection);
    }

    public function test_search_dates_compare_the_date_header_as_written(): void
    {
        $marker = 'Pop3Date'.random_int(10000, 99999);
        $folder = self::seedClient()->getFolder('INBOX');
        $folder->appendMessage("Date: Mon, 1 Jan 2024 23:30:00 -0500\r\nSubject: {$marker}Zone\r\n\r\nBody\r\n");
        $folder->appendMessage("Date: 5 Feb 2025 25:00:00 +0000\r\nSubject: {$marker}BadTime\r\n\r\nBody\r\n");
        $folder->appendMessage("Subject: {$marker}NoDate\r\n\r\nBody\r\n");
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $this->assertSame([$count - 2], imap_search($connection, "ON 1-Jan-2024 SUBJECT {$marker}Zone"));
        $this->assertSame([$count - 1], imap_search($connection, "ON 5-Feb-2025 SUBJECT {$marker}BadTime"));
        $this->assertSame([$count], imap_search($connection, "BEFORE 2-Jan-1970 SUBJECT {$marker}NoDate"));
        $this->assertFalse(imap_search($connection, "SINCE 2-Jan-1970 SUBJECT {$marker}NoDate"));

        imap_close($connection);
    }

    /**
     * The charsets c-client's utf8_csvalid table knows, as its refusal lists
     * them. POP3 has no SEARCH, so the charset is c-client's to check rather
     * than a server's.
     */
    private const BADCHARSET = '[BADCHARSET (US-ASCII UTF-8 UTF-7 ISO-8859-1 ISO-8859-2 ISO-8859-3 ISO-8859-4 '
        .'ISO-8859-5 ISO-8859-6 ISO-8859-7 ISO-8859-8 ISO-8859-9 ISO-8859-10 ISO-8859-11 ISO-8859-13 ISO-8859-14 '
        .'ISO-8859-15 ISO-8859-16 KOI8-R KOI8-U KOI8-RU TIS-620 VISCII GBK GB2312 CN-GB ISO-2022-CN CN-GB-12345 '
        .'BIG5 CN-BIG5 BIG-5 ISO-2022-JP EUC-JP SHIFT_JIS SHIFT-JIS ISO-2022-JP-1 ISO-2022-JP-2 ISO-2022-KR EUC-KR '
        .'KSC5601 KSC_5601 KS_C_5601-1987 KS_C_5601-1989 KS_C_5601-1992 KS_C_5601-1997 WINDOWS-874 CP874 '
        .'WINDOWS-936 CP936 WINDOWS-949 CP949 X-WINDOWS-949 WINDOWS-1250 CP1250 WINDOWS-1251 CP1251 WINDOWS-1252 '
        .'CP1252 WINDOWS-1253 CP1253 WINDOWS-1254 CP1254 WINDOWS-1255 CP1255 WINDOWS-1256 CP1256 WINDOWS-1257 '
        .'CP1257 WINDOWS-1258 CP1258 IBM367 IBM437 IBM737 IBM775 IBM850 IBM852 IBM855 IBM857 IBM860 IBM861 IBM862 '
        .'IBM863 IBM864 IBM865 IBM866 IBM869 IBM874 ANSI_X3.4-1968 UNICODE-1-1-UTF-7 UCS-2 UCS-4 UTF-16)] '
        .'Unknown charset: ';

    public function test_search_refuses_a_charset_c_client_does_not_know(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        imap_errors();

        $this->assertFalse(imap_search($connection, 'ALL', SE_FREE, 'X-NO-SUCH'));
        $this->assertSame([self::BADCHARSET.'X-NO-SUCH'], imap_errors());

        imap_close($connection);
    }

    /**
     * Left out, the charset is php_imap.c's NULL and nothing is checked.
     * Passed as '', it is a name, and not one in the table.
     */
    public function test_search_refuses_an_empty_charset_but_not_an_omitted_one(): void
    {
        self::seedClient()->getFolder('INBOX')->appendMessage("Subject: Pop3 Charset\r\n\r\nBody");
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        imap_errors();

        $this->assertFalse(imap_search($connection, 'ALL', SE_FREE, ''));
        $this->assertSame([self::BADCHARSET], imap_errors());
        $this->assertContains(imap_num_msg($connection), imap_search($connection, 'ALL', SE_FREE) ?: []);

        imap_close($connection);
    }

    /**
     * The terms are converted from the charset named to UTF-8 before they
     * are compared, so a Latin-1 term finds the same word in a UTF-8 body.
     */
    public function test_search_reads_the_terms_in_the_charset_given(): void
    {
        $marker = 'Pop3Latin'.random_int(10000, 99999);
        self::seedClient()->getFolder('INBOX')->appendMessage(
            "Subject: {$marker}\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n"
            ."Content-Transfer-Encoding: 8bit\r\n"
            ."\r\n"
            ."A caf\u{e9}{$marker} in the body\r\n"
        );
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $this->assertSame([$count], imap_search($connection, "BODY \"caf\xe9{$marker}\"", SE_FREE, 'ISO-8859-1'));

        imap_close($connection);
    }

    /**
     * POP3 sorts locally, and the local sort searches first, criteria or
     * not: the charset is checked either way, and a sort that cannot search
     * comes back empty.
     */
    public function test_sort_refuses_a_charset_c_client_does_not_know(): void
    {
        self::seedClient()->getFolder('INBOX')->appendMessage("Subject: Pop3 Sort Charset\r\n\r\nBody");
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        imap_errors();

        $this->assertSame([], imap_sort($connection, SORTARRIVAL, false, 0, 'ALL', 'X-NO-SUCH'));
        $this->assertSame([], imap_sort($connection, SORTARRIVAL, false, 0, null, 'X-NO-SUCH'));
        $this->assertSame([self::BADCHARSET.'X-NO-SUCH', self::BADCHARSET.'X-NO-SUCH'], imap_errors());

        imap_close($connection);
    }

    public function test_setflag_and_delete(): void
    {
        $client = self::seedClient();
        $folder = $client->getFolder('INBOX');
        $folder->appendMessage("Subject: Flag Me\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $this->assertTrue(imap_setflag_full($connection, (string) $count, '\\Seen'));
        $header = imap_headerinfo($connection, $count);
        $this->assertSame(' ', $header->Unseen);

        $this->assertTrue(imap_delete($connection, (string) $count));
        $this->assertTrue(imap_expunge($connection));
        $this->assertSame($count - 1, imap_num_msg($connection));

        imap_close($connection);
    }

    public function test_delete_keeps_the_numbering_until_expunge(): void
    {
        $client = self::seedClient();
        $folder = $client->getFolder('INBOX');
        $folder->appendMessage("Subject: Pop3 Keep A\r\n\r\nBody");
        $folder->appendMessage("Subject: Pop3 Keep B\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $this->assertTrue(imap_delete($connection, (string) ($count - 1)));
        $this->assertSame($count, imap_num_msg($connection));
        $this->assertStringContainsString('Pop3 Keep B', (string) imap_fetchheader($connection, $count));

        imap_close($connection);
    }

    public function test_expunge_renumbers_and_keeps_the_server_number_as_the_uid(): void
    {
        $client = self::seedClient();
        $folder = $client->getFolder('INBOX');
        $folder->appendMessage("Subject: Pop3 Renumber A\r\n\r\nBody");
        $folder->appendMessage("Subject: Pop3 Renumber B\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        imap_delete($connection, (string) ($count - 1));
        imap_expunge($connection);

        $this->assertSame($count - 1, imap_num_msg($connection));
        $this->assertStringContainsString('Pop3 Renumber B', (string) imap_fetchheader($connection, $count - 1));
        $this->assertSame($count, imap_uid($connection, $count - 1));
        $this->assertSame($count - 1, imap_msgno($connection, $count));

        imap_close($connection);
    }

    public function test_uidnext_stays_past_the_highest_server_number_after_an_expunge(): void
    {
        self::seedClient()->getFolder('INBOX')->appendMessage("Subject: Pop3 Uidnext\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        imap_delete($connection, '1');
        imap_expunge($connection);

        $status = imap_status($connection, self::pop3MailboxSpec(), SA_UIDNEXT);
        $this->assertSame($count + 1, $status->uidnext);

        imap_close($connection);
    }

    public function test_every_message_is_recent_for_the_session(): void
    {
        self::seedClient()->getFolder('INBOX')->appendMessage("Subject: Pop3 Recent\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $this->assertSame('N', imap_headerinfo($connection, $count)->Recent);
        imap_setflag_full($connection, (string) $count, '\\Seen');
        $this->assertSame('R', imap_headerinfo($connection, $count)->Recent);
        $this->assertSame(1, imap_fetch_overview($connection, (string) $count)[0]->recent);

        imap_close($connection);
    }

    public function test_reopen_starts_a_session_that_forgets_the_flags(): void
    {
        self::seedClient()->getFolder('INBOX')->appendMessage("Subject: Pop3 Reopen Seen\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);
        imap_setflag_full($connection, (string) $count, '\\Seen');
        $this->assertSame('R', imap_headerinfo($connection, $count)->Recent);

        $this->assertTrue(imap_reopen($connection, self::pop3MailboxSpec()));
        $this->assertSame('N', imap_headerinfo($connection, $count)->Recent);

        imap_close($connection);
    }

    /**
     * OP_HALFOPEN means nothing to the POP3 driver: the reopened session is
     * the mailbox, the way any reopen's is.
     */
    public function test_reopen_with_op_halfopen_still_opens_the_mailbox(): void
    {
        self::seedClient()->getFolder('INBOX')->appendMessage("Subject: Pop3 Reopen Half\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $this->assertTrue(imap_reopen($connection, self::pop3MailboxSpec(), OP_HALFOPEN));
        $this->assertSame($count, imap_num_msg($connection));
        $this->assertStringEndsWith('}INBOX', imap_check($connection)->Mailbox);

        imap_close($connection);
    }

    public function test_reopen_after_an_expunge_numbers_the_messages_afresh(): void
    {
        $folder = self::seedClient()->getFolder('INBOX');
        $folder->appendMessage("Subject: Pop3 Reopen A\r\n\r\nBody");
        $folder->appendMessage("Subject: Pop3 Reopen B\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);
        imap_delete($connection, '1');
        imap_expunge($connection);

        $this->assertTrue(imap_reopen($connection, self::pop3MailboxSpec()));
        $this->assertSame($count - 1, imap_num_msg($connection));
        $this->assertSame(1, imap_uid($connection, 1));

        imap_close($connection);
    }

    /**
     * A reopen keeps the password, so an unknown /user= is the only way to
     * make its login fail, and Dovecot's passdb accepts any username.
     */
    #[Group('greenmail-only')]
    public function test_a_reopen_that_cannot_log_in_closes_the_stream(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $spec = self::pop3MailboxSpec('INBOX', '/user=nosuchuser');

        $this->assertSame(
            [false, ["imap_reopen(): Couldn't re-open stream"]],
            $this->capturingWarnings(static fn () => imap_reopen($connection, $spec)),
        );

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('IMAP\Connection is already closed');
        imap_num_msg($connection);
    }

    public function test_close_without_expunge_deletes_nothing(): void
    {
        $client = self::seedClient();
        $client->getFolder('INBOX')->appendMessage("Subject: Pop3 Survivor\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);
        imap_delete($connection, (string) $count);
        imap_close($connection);

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $this->assertSame($count, imap_num_msg($connection));

        imap_close($connection);
    }

    public function test_mail_copy_and_move_fail(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());

        $this->assertFalse(imap_mail_copy($connection, '1', 'INBOX.Other'));
        $this->assertFalse(imap_mail_move($connection, '1', 'INBOX.Other'));

        imap_close($connection);
    }

    public function test_append_fails(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());

        $this->assertFalse(imap_append($connection, 'INBOX', "Subject: x\r\n\r\nbody"));

        imap_close($connection);
    }

    public function test_hierarchy_mutation_fails(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());

        $this->assertFalse(imap_createmailbox($connection, 'INBOX.Sub'));
        $this->assertFalse(imap_deletemailbox($connection, 'INBOX.Sub'));
        $this->assertFalse(imap_renamemailbox($connection, 'INBOX', 'INBOX.Renamed'));

        imap_close($connection);
    }

    public function test_subscribe_and_unsubscribe_noop_succeed(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());

        $this->assertTrue(imap_subscribe($connection, 'INBOX'));
        $this->assertTrue(imap_unsubscribe($connection, 'INBOX'));

        imap_close($connection);
    }

    public function test_list_returns_only_inbox(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());

        $list = imap_list($connection, self::pop3MailboxSpec(), '*');

        $this->assertNotFalse($list);
        $this->assertCount(1, $list);
        $this->assertStringEndsWith('INBOX', $list[0]);

        imap_close($connection);
    }

    /**
     * pop3_list() reports the INBOX with a NIL delimiter and
     * LATT_NOINFERIORS, and php_imap.c writes a NIL delimiter as the NUL
     * character it is in c-client, not as an empty string.
     */
    public function test_getmailboxes_reports_inbox_without_delimiter_or_inferiors(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());

        $list = imap_getmailboxes($connection, self::pop3MailboxSpec(), '*');

        $this->assertIsArray($list);
        $this->assertCount(1, $list);
        $this->assertStringEndsWith('}INBOX', $list[0]->name);
        $this->assertSame(LATT_NOINFERIORS, $list[0]->attributes);
        $this->assertSame("\0", $list[0]->delimiter);

        imap_close($connection);
    }

    public function test_fetchstructure_single_part(): void
    {
        $client = self::seedClient();
        $folder = $client->getFolder('INBOX');
        $folder->appendMessage("Subject: Structure Me\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nHello");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $structure = imap_fetchstructure($connection, $count);

        $this->assertSame(0, $structure->type); // TYPETEXT
        $this->assertSame('PLAIN', $structure->subtype);

        imap_close($connection);
    }

    /**
     * Dovecot stores the appended message with the bare LF already rewritten
     * as CRLF, so RETR has none left to send.
     */
    #[Group('greenmail-only')]
    public function test_a_bare_lf_in_the_body_is_data_not_a_line_break(): void
    {
        self::seedClient()->getFolder('INBOX')->appendMessage(
            "Subject: Bare LF\r\n\r\nline one\nline two\r\n"
        );

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $this->assertSame("line one\nline two\r\n", imap_body($connection, $count));
        $this->assertSame(19, imap_fetchstructure($connection, $count)->bytes);

        imap_close($connection);
    }

    /**
     * POP3 has no BODYSTRUCTURE, so c-client parses the message itself:
     * the sections are cut out of the RETR'd text by that parse.
     */
    public function test_fetchbody_and_fetchmime_cut_the_parts_out_of_a_multipart(): void
    {
        $count = $this->seedPop3(
            "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=B\r\n\r\n"
            ."--B\r\n\r\nfirst\r\nline\r\n"
            ."--B\r\nContent-Type: application/pdf; name=x.pdf\r\nContent-Disposition: attachment; filename=\"x.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nQUJD\r\n"
            ."--B--\r\n"
        );

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());

        $this->assertSame("first\r\nline", imap_fetchbody($connection, $count, '1'));
        $this->assertSame('QUJD', imap_fetchbody($connection, $count, '2'));
        $this->assertSame('', imap_fetchbody($connection, $count, '3'));
        $this->assertSame(
            "Content-Type: application/pdf; name=x.pdf\r\nContent-Disposition: attachment; filename=\"x.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n",
            imap_fetchmime($connection, $count, '2'),
        );

        $structure = imap_fetchstructure($connection, $count);
        $this->assertSame(169, $structure->bytes);
        $this->assertCount(2, $structure->parts);
        $this->assertSame(1, $structure->parts[0]->lines);
        $this->assertSame(11, $structure->parts[0]->bytes);
        $this->assertSame('US-ASCII', $structure->parts[0]->parameters[0]->value);
        $this->assertSame('ATTACHMENT', $structure->parts[1]->disposition);
        $this->assertSame('x.pdf', $structure->parts[1]->dparameters[0]->value);

        imap_close($connection);
    }

    public function test_a_delimiter_counts_only_at_the_start_of_a_line(): void
    {
        $count = $this->seedPop3(
            "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=XX\r\n\r\npreamble\r\n"
            ."--XX\r\n\r\nhello --XX world\r\n--XXY\r\nstill the first part\r\n"
            ."--XX\r\n\r\nsecond\r\n"
            ."--XX--\r\n"
        );

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());

        $this->assertSame("hello --XX world\r\n--XXY\r\nstill the first part", imap_fetchbody($connection, $count, '1'));
        $this->assertSame('second', imap_fetchbody($connection, $count, '2'));

        imap_close($connection);
    }

    public function test_an_embedded_message_has_a_structure_of_its_own(): void
    {
        $count = $this->seedPop3(
            "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=B\r\n\r\n"
            ."--B\r\nContent-Type: message/rfc822\r\n\r\nSubject: inner\r\nFrom: a@example.com\r\n\r\ninner body\r\n"
            ."--B--\r\n"
        );

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());

        $structure = imap_fetchstructure($connection, $count);
        $this->assertSame(2, $structure->parts[0]->type); // TYPEMESSAGE
        $this->assertSame('RFC822', $structure->parts[0]->subtype);
        $this->assertSame(0, $structure->parts[0]->parts[0]->type); // TYPETEXT
        $this->assertSame('inner body', imap_fetchbody($connection, $count, '1.1'));

        imap_close($connection);
    }

    public function test_a_quoted_parameter_keeps_its_semicolon(): void
    {
        $count = $this->seedPop3("MIME-Version: 1.0\r\nContent-Type: text/plain; name=\"a;b.txt\"; charset=us-ascii\r\n\r\nbody\r\n");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());

        $parameters = imap_fetchstructure($connection, $count)->parameters;
        $this->assertSame(['NAME', 'a;b.txt', 'CHARSET', 'us-ascii'], [
            $parameters[0]->attribute, $parameters[0]->value, $parameters[1]->attribute, $parameters[1]->value,
        ]);

        imap_close($connection);
    }

    public function test_a_mime_field_without_mime_version_is_logged_and_read(): void
    {
        $count = $this->seedPop3("Content-Type: multipart/mixed; boundary=B\r\n\r\n--B\r\n\r\nx\r\n--B--\r\n");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        imap_errors();

        $this->assertSame('x', imap_fetchbody($connection, $count, '1'));
        $this->assertSame(['Warning: MIME header encountered in non-MIME message'], imap_errors());

        imap_close($connection);
    }

    private function seedPop3(string $message): int
    {
        self::seedClient()->getFolder('INBOX')->appendMessage($message);
        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);
        imap_close($connection);

        return $count;
    }

    public function test_status_answers_from_the_message_count(): void
    {
        self::seedClient()->getFolder('INBOX')->appendMessage("Subject: Status Me\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);

        $status = imap_status($connection, self::pop3MailboxSpec(), SA_ALL);

        $this->assertNotFalse($status);
        $this->assertSame(SA_ALL, $status->flags);
        $this->assertSame($count, $status->messages);

        // POP3 carries no flags of its own, so every message a session finds
        // is new to it and none of them is read.
        $this->assertSame($count, $status->recent);
        $this->assertSame($count, $status->unseen);

        $this->assertSame($count + 1, $status->uidnext);

        // The value is invented by the driver rather than reported by the
        // server, so only its presence is the contract.
        $this->assertIsInt($status->uidvalidity);

        imap_close($connection);
    }
}
