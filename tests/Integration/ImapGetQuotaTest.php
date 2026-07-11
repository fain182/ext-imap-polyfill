<?php

namespace ImapPolyfill\Tests\Integration;

class ImapGetQuotaTest extends GreenmailTestCase
{
    public function test_returns_false_when_the_server_rejects_getquota(): void
    {
        // GreenMail advertises QUOTA but only implements SETQUOTA and
        // GETQUOTAROOT: GETQUOTA draws a BAD, which both the real extension
        // and this polyfill surface as a warning, false, and a pushed error.
        // (The success path's QUOTA-response parsing is shared with
        // imap_get_quotaroot(), covered in ImapGetQuotarootTest.)
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);
        imap_errors();

        $this->assertFalse(@imap_get_quota($connection, ''));

        $errors = imap_errors();
        $this->assertIsArray($errors);
        $this->assertStringContainsString('Invalid command', implode(' ', $errors));
    }
}
