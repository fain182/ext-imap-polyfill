<?php

namespace ImapPolyfill\Tests\Integration;

class ImapListTest extends GreenmailTestCase
{
    public function test_returns_full_mailbox_names_matching_the_pattern(): void
    {
        $folderName = 'ListBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());

        $result = imap_list($connection, self::mailboxSpec(''), '*');

        $this->assertIsArray($result);
        $this->assertContains(self::mailboxSpec($folderName), $result);
    }

    public function test_takes_the_prefix_off_the_pattern_when_the_reference_is_empty(): void
    {
        $folderName = 'ListPattern'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());

        $this->assertSame([self::mailboxSpec($folderName)], imap_list($connection, '', self::mailboxSpec($folderName)));
    }

    public function test_a_pattern_carrying_a_prefix_overrides_the_reference(): void
    {
        $folderName = 'ListOverride'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());

        $this->assertSame([self::mailboxSpec($folderName)], imap_list($connection, self::mailboxSpec('Elsewhere'), self::mailboxSpec($folderName)));
    }

    /**
     * A port of 0, a service other than imap, a brace that never closes:
     * none is a spec the IMAP driver serves, so nothing is listed and
     * nothing is said, rather than the prefix being stripped regardless.
     */
    public function test_a_prefix_the_imap_driver_refuses_lists_nothing(): void
    {
        $folderName = 'ListRefused'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::user(), self::password());
        imap_errors();

        foreach (['{'.self::host().':0}', '{'.self::host().':'.self::port().'/pop3}', '{'.self::host()] as $prefix) {
            $this->assertFalse(imap_list($connection, $prefix, $folderName), $prefix);
            $this->assertFalse(imap_list($connection, '', $prefix.$folderName), $prefix);
        }

        $this->assertFalse(imap_errors());
        $this->assertSame([self::mailboxSpec($folderName)], imap_list($connection, self::mailboxSpec(''), $folderName));
    }
}
