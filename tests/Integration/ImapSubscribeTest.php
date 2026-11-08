<?php

namespace ImapPolyfill\Tests\Integration;

class ImapSubscribeTest extends GreenmailTestCase
{
    public function test_subscribes_and_unsubscribes_a_mailbox(): void
    {
        $folderName = 'SubscribeBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());

        $this->assertTrue(imap_subscribe($connection, self::mailboxSpec($folderName)));
        $this->assertTrue(imap_unsubscribe($connection, self::mailboxSpec($folderName)));
    }

    /**
     * mail_unsubscribe() hands a spec no driver serves to c-client's local
     * subscription file, and there is none: hence the message.
     */
    public function test_refuses_a_prefix_the_imap_driver_does_not_serve(): void
    {
        $folderName = 'SubscribeRefused'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());
        imap_errors();
        $mailbox = '{'.self::host().':0}'.$folderName;

        $this->assertFalse(imap_subscribe($connection, $mailbox));
        $this->assertFalse(imap_unsubscribe($connection, $mailbox));
        $this->assertSame([
            "Can't subscribe to mailbox {$mailbox}: invalid remote specification",
            'No subscriptions',
        ], imap_errors());
    }
}
