<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\Support\SeedClient;

/**
 * POP3 message reading, flags/delete, search, and structure. Greenmail's
 * POP3 service only ever exposes a single shared INBOX (see
 * Pop3OpenTest::currentCount() for why assertions use relative counts/UIDs
 * rather than absolute msgnos where the shared fixture matters).
 */
final class Pop3MailboxTest extends GreenmailTestCase
{
    private function seedClient(): SeedClient
    {
        $client = new SeedClient(self::host(), self::port(), self::USER, self::PASSWORD);

        return $client;
    }

    public function test_headerinfo_body_and_fetchbody(): void
    {
        $client = $this->seedClient();
        $folder = $client->getFolder('INBOX');
        $folder->appendMessage(
            "From: alice@example.com\r\nTo: bob@example.com\r\nSubject: Hello\r\n\r\nHello body\r\n"
        );

        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);
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
        $client = $this->seedClient();
        $folder = $client->getFolder('INBOX');
        $folder->appendMessage("Subject: Overview Me\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);
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
        $folder = $this->seedClient()->getFolder('INBOX');
        $folder->appendMessage("Subject: Zulus\r\n\r\nBody");
        $folder->appendMessage("Subject: Re: Zulu\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);
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
        $this->seedClient()->getFolder('INBOX')->appendMessage("Subject: Uid Rule\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);
        $count = imap_num_msg($connection);

        $this->assertSame($count, imap_uid($connection, $count));
        $this->assertSame($count, imap_msgno($connection, $count));

        imap_close($connection);
    }

    public function test_uid_stable_across_reconnect(): void
    {
        $client = $this->seedClient();
        $folder = $client->getFolder('INBOX');
        $folder->appendMessage("Subject: Uid Me\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);
        $count = imap_num_msg($connection);
        $uid = imap_uid($connection, $count);
        imap_close($connection);

        $reconnected = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);
        $this->assertSame($uid, imap_uid($reconnected, $count));
        $this->assertSame($count, imap_msgno($reconnected, $uid));
        imap_close($reconnected);
    }

    public function test_search_all_and_subject(): void
    {
        $client = $this->seedClient();
        $folder = $client->getFolder('INBOX');
        $marker = 'Uniq'.random_int(10000, 99999);
        $folder->appendMessage("Subject: {$marker}\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);
        $count = imap_num_msg($connection);

        $all = imap_search($connection, 'ALL');
        $this->assertIsArray($all);
        $this->assertContains($count, $all);

        $bySubject = imap_search($connection, 'SUBJECT '.$marker);
        $this->assertSame([$count], $bySubject);

        imap_close($connection);
    }

    public function test_setflag_and_delete(): void
    {
        $client = $this->seedClient();
        $folder = $client->getFolder('INBOX');
        $folder->appendMessage("Subject: Flag Me\r\n\r\nBody");

        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);
        $count = imap_num_msg($connection);

        $this->assertTrue(imap_setflag_full($connection, (string) $count, '\\Seen'));
        $header = imap_headerinfo($connection, $count);
        $this->assertSame(' ', $header->Unseen);

        $this->assertTrue(imap_delete($connection, (string) $count));
        $this->assertTrue(imap_expunge($connection));
        $this->assertSame($count - 1, imap_num_msg($connection));

        imap_close($connection);
    }

    public function test_mail_copy_and_move_fail(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);

        $this->assertFalse(@imap_mail_copy($connection, '1', 'INBOX.Other'));
        $this->assertFalse(@imap_mail_move($connection, '1', 'INBOX.Other'));

        imap_close($connection);
    }

    public function test_append_fails(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);

        $this->assertFalse(@imap_append($connection, 'INBOX', "Subject: x\r\n\r\nbody"));

        imap_close($connection);
    }

    public function test_hierarchy_mutation_fails(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);

        $this->assertFalse(@imap_createmailbox($connection, 'INBOX.Sub'));
        $this->assertFalse(@imap_deletemailbox($connection, 'INBOX.Sub'));
        $this->assertFalse(@imap_renamemailbox($connection, 'INBOX', 'INBOX.Renamed'));

        imap_close($connection);
    }

    public function test_subscribe_and_unsubscribe_noop_succeed(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);

        $this->assertTrue(imap_subscribe($connection, 'INBOX'));
        $this->assertTrue(imap_unsubscribe($connection, 'INBOX'));

        imap_close($connection);
    }

    public function test_list_returns_only_inbox(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);

        $list = imap_list($connection, self::pop3MailboxSpec(), '*');

        $this->assertNotFalse($list);
        $this->assertCount(1, $list);
        $this->assertStringEndsWith('INBOX', $list[0]);

        imap_close($connection);
    }

    public function test_fetchstructure_single_part(): void
    {
        $client = $this->seedClient();
        $folder = $client->getFolder('INBOX');
        $folder->appendMessage("Subject: Structure Me\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nHello");

        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);
        $count = imap_num_msg($connection);

        $structure = imap_fetchstructure($connection, $count);

        $this->assertSame(0, $structure->type); // TYPETEXT
        $this->assertSame('PLAIN', $structure->subtype);

        imap_close($connection);
    }
}
