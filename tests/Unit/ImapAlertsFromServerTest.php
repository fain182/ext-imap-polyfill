<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Connection\Imap\ImapEngineConnection;
use ImapPolyfill\Tests\ResetsErrorStack;
use PHPUnit\Framework\TestCase;

/**
 * Alerts are unsolicited: no command asks for one, the server sends it when
 * it has something to tell the user (quota, expiring password, admin
 * notice). Neither fixture can be made to produce one — Greenmail never
 * sends `[ALERT]` at all, and Dovecot with the quota plugin and a 1k limit
 * answers an over-quota APPEND with a *tagged* `NO [OVERQUOTA]`, which
 * c-client would ignore even if it carried the alert code, since only
 * untagged responses reach the stack.
 *
 * So these drive a connection over a faked stream instead. That pins the
 * capture and the filtering; it does not prove a real server reaches us in
 * this shape. Skipped under the real extension, which reads its own alert
 * stack, not this one.
 */
class ImapAlertsFromServerTest extends TestCase
{
    use ResetsErrorStack;

    protected function setUp(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped('imap_alerts() reads the real extension\'s own alert stack.');
        }
    }

    /**
     * @param string[] $responses replies the fake server sends after its greeting
     */
    private function connectionServing(array $responses): ImapEngineConnection
    {
        $connection = ImapEngineConnection::fake(['* OK IMAP4rev1 ready', ...$responses]);
        $connection->connect('fake.example.com');

        return $connection;
    }

    public function test_an_untagged_alert_reaches_imap_alerts(): void
    {
        $connection = $this->connectionServing([
            '* OK [ALERT] Mailbox is over quota',
            'TAG1 OK NOOP completed',
        ]);

        $connection->noop();

        $this->assertSame(['[ALERT] Mailbox is over quota'], imap_alerts());
    }

    /**
     * The greeting arrives before any command is in flight, and it's where
     * servers most often warn about the account.
     */
    public function test_an_alert_in_the_greeting_is_recorded(): void
    {
        $connection = ImapEngineConnection::fake([
            '* OK [ALERT] Password expires tomorrow',
        ]);

        $connection->connect('fake.example.com');

        $this->assertSame(['[ALERT] Password expires tomorrow'], imap_alerts());
    }

    public function test_untagged_responses_without_an_alert_code_are_ignored(): void
    {
        $connection = $this->connectionServing([
            '* OK [UIDVALIDITY 1234] UIDs valid',
            '* OK Still here',
            'TAG1 OK NOOP completed',
        ]);

        $connection->noop();

        $this->assertFalse(imap_alerts());
    }

    /**
     * c-client parses tagged replies with notification suppressed, so an
     * alert on a command's completion line is not recorded.
     */
    public function test_an_alert_on_a_tagged_reply_is_ignored(): void
    {
        $connection = $this->connectionServing([
            'TAG1 OK [ALERT] Take note of this',
        ]);

        $connection->noop();

        $this->assertFalse(imap_alerts());
    }

    public function test_alerts_accumulate_in_arrival_order(): void
    {
        $connection = $this->connectionServing([
            '* OK [ALERT] First',
            '* OK [ALERT] Second',
            'TAG1 OK NOOP completed',
        ]);

        $connection->noop();

        $this->assertSame(['[ALERT] First', '[ALERT] Second'], imap_alerts());
    }
}
