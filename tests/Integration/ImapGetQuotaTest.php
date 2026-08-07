<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

class ImapGetQuotaTest extends GreenmailTestCase
{
    /**
     * Sets the quota it then reads; Dovecot's quota is read-only,
     * configured server side.
     */
    #[Group('greenmail-only')]
    public function test_reports_the_quota_set_on_a_root(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::user(), self::password());
        $this->assertTrue(imap_set_quota($connection, 'INBOX', 768));

        $quota = imap_get_quota($connection, 'INBOX');

        $this->assertIsArray($quota);
        $this->assertArrayHasKey('STORAGE', $quota);
        $this->assertIsInt($quota['STORAGE']['usage']);
        $this->assertSame(768, $quota['STORAGE']['limit']);
        // The STORAGE resource is mirrored into top-level usage/limit keys.
        $this->assertSame($quota['STORAGE']['usage'], $quota['usage']);
        $this->assertSame(768, $quota['limit']);
    }

    /**
     * A quota root the server doesn't know is a NO, and imap_get_quota()
     * answers false. Only testable since GreenMail 2.1.10 (upstream #1024):
     * before that, once any quota existed, every root reported it.
     */
    public function test_returns_false_for_an_unknown_quota_root(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::user(), self::password());
        imap_set_quota($connection, 'INBOX', 768);

        $this->assertFalse(@imap_get_quota($connection, 'NoSuchRoot'.random_int(10000, 99999)));
    }
}
