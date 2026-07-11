<?php

namespace ImapPolyfill\Tests\Integration;

class ImapGetQuotarootTest extends GreenmailTestCase
{
    public function test_reports_each_resource_plus_legacy_top_level_storage_keys(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);
        // See ImapSetQuotaTest for why the root is "INBOX" and not "".
        $this->assertTrue(imap_set_quota($connection, 'INBOX', 512));

        $quota = imap_get_quotaroot($connection, 'INBOX');

        $this->assertIsArray($quota);
        $this->assertArrayHasKey('STORAGE', $quota);
        $this->assertIsInt($quota['STORAGE']['usage']);
        $this->assertSame(512, $quota['STORAGE']['limit']);
        // The STORAGE resource is mirrored into top-level usage/limit keys,
        // which the real extension's callback writes first.
        $this->assertSame($quota['STORAGE']['usage'], $quota['usage']);
        $this->assertSame(512, $quota['limit']);
        $this->assertSame(['usage', 'limit', 'STORAGE'], array_keys($quota));
    }
}
