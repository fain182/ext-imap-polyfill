<?php

namespace ImapPolyfill\Tests\Integration;

class ImapGetQuotaTest extends GreenmailTestCase
{
    public function test_reports_the_quota_set_on_a_root(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);
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
}
