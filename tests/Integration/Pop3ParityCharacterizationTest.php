<?php

namespace ImapPolyfill\Tests\Integration;

/**
 * Exploratory characterization test: observes how the *real* ext-imap
 * extension behaves when a mailbox spec uses the /pop3 service, so we can
 * decide whether/how to implement POP3 support in the polyfill.
 *
 * Runs only under `make parity` (real ext-imap) — this polyfill doesn't
 * speak POP3 yet, and Greenmail's POP3 service only ever exposes a mailbox
 * named INBOX, so there's nothing meaningful to assert against the polyfill
 * side yet.
 */
final class Pop3ParityCharacterizationTest extends GreenmailTestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('imap')) {
            $this->markTestSkipped('POP3 characterization only runs against the real ext-imap extension (make parity).');
        }
    }

    public function test_characterize_pop3_behavior(): void
    {
        // POP3 has no APPEND command; seed INBOX over IMAP first.
        $seedClient = (new \Webklex\PHPIMAP\ClientManager())->make([
            'host' => self::host(),
            'port' => self::port(),
            'encryption' => false,
            'validate_cert' => false,
            'username' => self::USER,
            'password' => self::PASSWORD,
            'protocol' => 'imap',
        ]);
        $seedClient->connect();
        $seedClient->getFolder('INBOX')->appendMessage("Subject: Pop3 Parity\r\n\r\nHello from parity test");

        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);
        $this->dump('imap_open', $connection);

        if ($connection === false) {
            $this->dump('imap_last_error', imap_last_error());
            $this->fail('imap_open over /pop3 failed against real ext-imap; see dump above.');
        }

        $this->dump('imap_num_msg', imap_num_msg($connection));
        $this->dump('imap_headerinfo(1)', imap_headerinfo($connection, 1));
        $this->dump('imap_fetchbody(1,1)', imap_fetchbody($connection, 1, '1'));
        $this->dump('imap_body(1)', imap_body($connection, 1));
        $this->dump('imap_search(ALL)', imap_search($connection, 'ALL'));
        $this->dump('imap_setflag_full(\\Seen)', imap_setflag_full($connection, '1', '\\Seen'));
        $this->dump('imap_headerinfo(1) after setflag', imap_headerinfo($connection, 1));
        $this->dump('imap_list', imap_list($connection, self::pop3MailboxSpec(), '*'));
        $this->dump('imap_createmailbox', @imap_createmailbox($connection, self::pop3MailboxSpec().'.Sub'));
        $this->dump('imap_createmailbox error', imap_last_error());
        $this->dump('imap_delete(1)', imap_delete($connection, '1'));
        $this->dump('imap_expunge', imap_expunge($connection));
        $this->dump('imap_num_msg after expunge', imap_num_msg($connection));

        imap_close($connection);

        $this->assertTrue(true);
    }

    public function test_characterize_pop3_edge_cases(): void
    {
        $seedClient = (new \Webklex\PHPIMAP\ClientManager())->make([
            'host' => self::host(),
            'port' => self::port(),
            'encryption' => false,
            'validate_cert' => false,
            'username' => self::USER,
            'password' => self::PASSWORD,
            'protocol' => 'imap',
        ]);
        $seedClient->connect();
        $folder = $seedClient->getFolder('INBOX');
        $folder->appendMessage("From: alice@example.com\r\nTo: bob@example.com\r\nSubject: First\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nFirst body");
        $folder->appendMessage("From: carol@example.com\r\nTo: bob@example.com\r\nSubject: Second\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\nSecond body");

        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);
        $this->dump('imap_num_msg (2 msgs)', imap_num_msg($connection));
        $this->dump('imap_headerinfo(1) From/To/Date', (function () use ($connection) {
            $h = imap_headerinfo($connection, 1);

            return [$h->from ?? null, $h->to ?? null, $h->date ?? null, $h->subject ?? null];
        })());

        $uid1 = imap_uid($connection, 1);
        $uid2 = imap_uid($connection, 2);
        $this->dump('imap_uid(1), imap_uid(2)', [$uid1, $uid2]);
        $this->dump('imap_msgno(uid1)', imap_msgno($connection, $uid1));

        $this->dump('imap_fetchstructure(1)', imap_fetchstructure($connection, 1));
        $this->dump('imap_fetch_overview(1:2)', imap_fetch_overview($connection, '1:2'));
        $this->dump('imap_status', imap_status($connection, self::pop3MailboxSpec(), SA_ALL));
        $this->dump('imap_ping', imap_ping($connection));
        $this->dump('imap_check', imap_check($connection));

        // Invalid message number.
        $this->dump('imap_headerinfo(99)', @imap_headerinfo($connection, 99));
        $this->dump('imap_headerinfo(99) error', imap_last_error());

        // mail_copy/move: POP3 has no COPY/MOVE server-side.
        $this->dump('imap_mail_copy', @imap_mail_copy($connection, '1', 'INBOX.Other'));
        $this->dump('imap_mail_copy error', imap_last_error());
        $this->dump('imap_mail_move', @imap_mail_move($connection, '1', 'INBOX.Other'));
        $this->dump('imap_mail_move error', imap_last_error());

        imap_close($connection);

        // Reconnect and check UID stability across separate connections
        // (real POP3 servers use UIDL for this; matters for imap_uid callers
        // who cache UIDs across requests).
        $reconnected = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);
        $this->dump('uid1 stable across reconnect', imap_uid($reconnected, 1) === $uid1);
        imap_close($reconnected);

        // OP_READONLY: does imap_open itself refuse pop3?
        $readonly = @imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD, OP_READONLY);
        $this->dump('imap_open with OP_READONLY', $readonly);
        $this->dump('imap_open with OP_READONLY error', imap_last_error());
        if ($readonly !== false) {
            $this->dump('imap_delete under OP_READONLY', imap_delete($readonly, '1'));
            imap_close($readonly);
        }

        $this->assertTrue(true);
    }

    public function test_characterize_pop3_hierarchy_and_append_errors(): void
    {
        $connection = imap_open(self::pop3MailboxSpec(), self::USER, self::PASSWORD);

        $this->dump('imap_createmailbox(bare name)', @imap_createmailbox($connection, 'INBOX.Sub'));
        $this->dump('imap_createmailbox error', imap_last_error());

        $this->dump('imap_deletemailbox(bare name)', @imap_deletemailbox($connection, 'INBOX.Sub'));
        $this->dump('imap_deletemailbox error', imap_last_error());

        $this->dump('imap_renamemailbox', @imap_renamemailbox($connection, 'INBOX', 'INBOX.Renamed'));
        $this->dump('imap_renamemailbox error', imap_last_error());

        $this->dump('imap_subscribe', @imap_subscribe($connection, 'INBOX'));
        $this->dump('imap_subscribe error', imap_last_error());

        $this->dump('imap_unsubscribe', @imap_unsubscribe($connection, 'INBOX'));
        $this->dump('imap_unsubscribe error', imap_last_error());

        $this->dump('imap_append', @imap_append($connection, 'INBOX', "Subject: x\r\n\r\nbody"));
        $this->dump('imap_append error', imap_last_error());

        // Non-INBOX folder in the mailbox spec itself.
        $other = @imap_open(sprintf('{%s:%d/pop3/novalidate-cert}Other', self::host(), self::pop3Port()), self::USER, self::PASSWORD);
        $this->dump('imap_open non-INBOX folder', $other);
        $this->dump('imap_open non-INBOX folder error', imap_last_error());

        imap_close($connection);
        $this->assertTrue(true);
    }

    private function dump(string $label, mixed $value): void
    {
        fwrite(STDERR, sprintf("\n[pop3-parity] %s => %s\n", $label, var_export($value, true)));
    }
}
