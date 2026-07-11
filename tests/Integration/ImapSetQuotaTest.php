<?php

namespace ImapPolyfill\Tests\Integration;

class ImapSetQuotaTest extends GreenmailTestCase
{
    public function test_sets_a_storage_quota_the_server_confirms(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);

        // Root "INBOX", not "": GreenMail echoes an empty quota root back as
        // a malformed «QUOTA """" (...)» line that c-client can't parse, so
        // against the real extension a quota set on "" never becomes visible.
        $this->assertTrue(imap_set_quota($connection, 'INBOX', 1024));

        $quota = imap_get_quotaroot($connection, 'INBOX');
        $this->assertIsArray($quota);
        $this->assertSame(1024, $quota['limit']);
    }
}
