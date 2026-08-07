<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

class ImapSetQuotaTest extends GreenmailTestCase
{
    /**
     * Dovecot implements no SETQUOTA at all.
     */
    #[Group('greenmail-only')]
    public function test_sets_a_storage_quota_the_server_confirms(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);

        $this->assertTrue(imap_set_quota($connection, 'INBOX', 1024));

        $quota = imap_get_quotaroot($connection, 'INBOX');
        $this->assertIsArray($quota);
        $this->assertSame(1024, $quota['limit']);
    }
}
