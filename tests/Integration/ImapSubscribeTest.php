<?php

namespace ImapPolyfill\Tests\Integration;

class ImapSubscribeTest extends GreenmailTestCase
{
    public function test_subscribes_and_unsubscribes_a_mailbox(): void
    {
        $folderName = 'SubscribeBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::USER, self::PASSWORD);

        $this->assertTrue(imap_subscribe($connection, self::mailboxSpec($folderName)));
        $this->assertTrue(imap_unsubscribe($connection, self::mailboxSpec($folderName)));
    }
}
