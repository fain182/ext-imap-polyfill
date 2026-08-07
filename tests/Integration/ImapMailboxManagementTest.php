<?php

namespace ImapPolyfill\Tests\Integration;

class ImapMailboxManagementTest extends GreenmailTestCase
{
    public function test_creates_a_mailbox(): void
    {
        $folderName = 'CreateBox'.uniqid();
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());

        $result = imap_createmailbox($connection, self::mailboxSpec($folderName));

        $this->assertTrue($result);
        $this->assertContains(self::mailboxSpec($folderName), imap_list($connection, self::mailboxSpec(''), '*'));
    }

    public function test_imap_create_is_an_alias(): void
    {
        $folderName = 'CreateAliasBox'.uniqid();
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());

        $this->assertTrue(imap_create($connection, self::mailboxSpec($folderName)));
    }

    public function test_deletes_a_mailbox(): void
    {
        $folderName = 'DeleteBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());

        $result = imap_deletemailbox($connection, self::mailboxSpec($folderName));

        $this->assertTrue($result);
        $this->assertNotContains(self::mailboxSpec($folderName), imap_list($connection, self::mailboxSpec(''), '*') ?: []);
    }

    public function test_renames_a_mailbox(): void
    {
        $folderName = 'RenameBox'.uniqid();
        $newName = 'RenamedBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());

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
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());

        $this->assertTrue(imap_rename($connection, self::mailboxSpec($folderName), self::mailboxSpec($newName)));
    }

    /**
     * A server refusing a command quotes the offending mailbox name back,
     * and a non-ASCII name makes that response carry bytes outside ASCII.
     * The name each server echoes differs (one decodes the modified UTF-7,
     * the other repeats it verbatim), so only the ASCII tail is pinned —
     * what matters is that the rejection reaches the error stack as the
     * server's own text at all.
     */
    public function test_a_rejected_command_naming_a_non_ascii_mailbox_records_the_server_response(): void
    {
        $suffix = uniqid();
        $missing = mb_convert_encoding('MissingBox_àèì_'.$suffix, 'UTF7-IMAP', 'UTF-8');
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());
        imap_errors();

        $this->assertFalse(imap_deletemailbox($connection, self::mailboxSpec($missing)));
        $this->assertStringContainsString($suffix, implode('|', imap_errors() ?: []));
    }
}
