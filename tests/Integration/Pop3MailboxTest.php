<?php

namespace ImapPolyfill\Tests\Integration;

/**
 * POP3 message reading, flags/delete, search, and structure. Greenmail's
 * POP3 service only ever exposes a single shared INBOX (see
 * Pop3OpenTest::currentCount() for why assertions use relative counts/UIDs
 * rather than absolute msgnos where the shared fixture matters).
 */
final class Pop3MailboxTest extends GreenmailTestCase
{
    private function seedClient(): \Webklex\PHPIMAP\Client
    {
        $client = (new \Webklex\PHPIMAP\ClientManager())->make([
            'host' => self::host(),
            'port' => self::port(),
            'encryption' => false,
            'validate_cert' => false,
            'username' => self::USER,
            'password' => self::PASSWORD,
            'protocol' => 'imap',
        ]);
        $client->connect();

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
