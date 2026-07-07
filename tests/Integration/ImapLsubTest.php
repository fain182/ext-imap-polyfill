<?php

namespace ImapPolyfill\Tests\Integration;

class ImapLsubTest extends GreenmailTestCase
{
    public function test_lists_only_subscribed_folders(): void
    {
        $uniq = uniqid();
        $subscribedName = "LsubBox{$uniq}Sub";
        $unsubscribedName = "LsubBox{$uniq}Other";
        $this->makeFolder($subscribedName);
        $this->makeFolder($unsubscribedName);

        $connection = imap_open(self::mailboxSpec('INBOX'), self::USER, self::PASSWORD);
        imap_subscribe($connection, self::mailboxSpec($subscribedName));

        $result = imap_lsub($connection, self::mailboxSpec(''), "LsubBox{$uniq}*");

        $this->assertIsArray($result);
        $this->assertContains(self::mailboxSpec($subscribedName), $result);
        $this->assertNotContains(self::mailboxSpec($unsubscribedName), $result);
    }

    public function test_imap_listsubscribed_is_an_alias_of_imap_lsub(): void
    {
        $uniq = uniqid();
        $folderName = "LsubBox{$uniq}Alias";
        $this->makeFolder($folderName);

        $connection = imap_open(self::mailboxSpec('INBOX'), self::USER, self::PASSWORD);
        imap_subscribe($connection, self::mailboxSpec($folderName));

        $this->assertSame(
            imap_lsub($connection, self::mailboxSpec(''), "LsubBox{$uniq}*"),
            imap_listsubscribed($connection, self::mailboxSpec(''), "LsubBox{$uniq}*")
        );
    }

    public function test_returns_false_when_nothing_is_subscribed(): void
    {
        $connection = imap_open(self::mailboxSpec('INBOX'), self::USER, self::PASSWORD);

        $this->assertFalse(imap_lsub($connection, self::mailboxSpec(''), 'NoSuchFolderXYZ'.uniqid().'*'));
    }
}
