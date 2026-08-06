<?php

namespace ImapPolyfill\Tests\Integration;

/**
 * imap_getacl()/imap_setacl() against a server that advertises ACL (RFC
 * 4314), which Greenmail does not: it answers BAD to every ACL command.
 */
final class DovecotAclTest extends DovecotTestCase
{
    public function test_getacl_reports_the_owners_rights(): void
    {
        $folderName = 'DcAcl'.random_int(10000, 99999);
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $acl = imap_getacl($connection, $folderName);

        $this->assertIsArray($acl);
        $this->assertArrayHasKey(self::USER, $acl);
        // The owner holds at least lookup and read on a folder they created.
        $this->assertStringContainsString('l', $acl[self::USER]);
        $this->assertStringContainsString('r', $acl[self::USER]);

        imap_close($connection);
    }

    public function test_setacl_grants_rights_that_getacl_then_reports(): void
    {
        $folderName = 'DcAclSet'.random_int(10000, 99999);
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertTrue(imap_setacl($connection, $folderName, 'someoneelse', 'lr'));

        $acl = imap_getacl($connection, $folderName);
        $this->assertSame('lr', $acl['someoneelse'] ?? null);

        imap_close($connection);
    }

    public function test_setacl_can_widen_and_narrow_an_identifiers_rights(): void
    {
        $folderName = 'DcAclEdit'.random_int(10000, 99999);
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        imap_setacl($connection, $folderName, 'someoneelse', 'lr');
        imap_setacl($connection, $folderName, 'someoneelse', 'lrw');

        $acl = imap_getacl($connection, $folderName);
        $this->assertSame('lrw', $acl['someoneelse'] ?? null);

        imap_close($connection);
    }

    public function test_getacl_returns_false_for_a_nonexistent_mailbox(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);

        $this->assertFalse(@imap_getacl($connection, 'NoSuchFolder'.random_int(10000, 99999)));

        imap_close($connection);
    }

    public function test_setacl_returns_false_for_a_nonexistent_mailbox(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);

        $this->assertFalse(imap_setacl($connection, 'NoSuchFolder'.random_int(10000, 99999), 'someoneelse', 'lr'));

        imap_close($connection);
    }
}
