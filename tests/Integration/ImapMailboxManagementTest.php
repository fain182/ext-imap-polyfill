<?php

namespace ImapPolyfill\Tests\Integration;

class ImapMailboxManagementTest extends GreenmailTestCase
{
    public function test_creates_a_mailbox(): void
    {
        $folderName = 'CreateBox'.uniqid();
        $connection = imap_open(self::mailboxSpec('INBOX'), self::USER, self::PASSWORD);

        $result = imap_createmailbox($connection, self::mailboxSpec($folderName));

        $this->assertTrue($result);
        $this->assertContains(self::mailboxSpec($folderName), imap_list($connection, self::mailboxSpec(''), '*'));
    }

    public function test_imap_create_is_an_alias(): void
    {
        $folderName = 'CreateAliasBox'.uniqid();
        $connection = imap_open(self::mailboxSpec('INBOX'), self::USER, self::PASSWORD);

        $this->assertTrue(imap_create($connection, self::mailboxSpec($folderName)));
    }

    public function test_deletes_a_mailbox(): void
    {
        $folderName = 'DeleteBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::USER, self::PASSWORD);

        $result = imap_deletemailbox($connection, self::mailboxSpec($folderName));

        $this->assertTrue($result);
        $this->assertNotContains(self::mailboxSpec($folderName), imap_list($connection, self::mailboxSpec(''), '*') ?: []);
    }

    public function test_renames_a_mailbox(): void
    {
        $folderName = 'RenameBox'.uniqid();
        $newName = 'RenamedBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::USER, self::PASSWORD);

        $result = imap_renamemailbox($connection, self::mailboxSpec($folderName), self::mailboxSpec($newName));

        $this->assertTrue($result);
        $folders = imap_list($connection, self::mailboxSpec(''), '*');
        $this->assertContains(self::mailboxSpec($newName), $folders);
        $this->assertNotContains(self::mailboxSpec($folderName), $folders);
    }

    public function test_imap_rename_is_an_alias(): void
    {
        $folderName = 'RenameAliasBox'.uniqid();
        $newName = 'RenamedAliasBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::USER, self::PASSWORD);

        $this->assertTrue(imap_rename($connection, self::mailboxSpec($folderName), self::mailboxSpec($newName)));
    }
}
