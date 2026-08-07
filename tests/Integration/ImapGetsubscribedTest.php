<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

class ImapGetsubscribedTest extends GreenmailTestCase
{
    /**
     * Asserts an exact folder set, which Dovecot's preexisting mailboxes
     * change.
     */
    #[Group('greenmail-only')]
    public function test_returns_mailbox_objects_for_subscribed_folders_only(): void
    {
        $uniq = uniqid();
        $subscribedName = "GetSubBox{$uniq}Sub";
        $unsubscribedName = "GetSubBox{$uniq}Other";
        $this->makeFolder($subscribedName);
        $this->makeFolder($unsubscribedName);

        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());
        imap_subscribe($connection, self::mailboxSpec($subscribedName));

        $result = imap_getsubscribed($connection, self::mailboxSpec(''), "GetSubBox{$uniq}*");

        $this->assertIsArray($result);
        $names = array_map(static fn (\stdClass $m) => $m->name, $result);
        $this->assertContains(self::mailboxSpec($subscribedName), $names);
        $this->assertNotContains(self::mailboxSpec($unsubscribedName), $names);

        $match = array_values(array_filter($result, static fn (\stdClass $m) => $m->name === self::mailboxSpec($subscribedName)))[0];
        $this->assertSame('.', $match->delimiter);
        $this->assertIsInt($match->attributes);
    }
}
