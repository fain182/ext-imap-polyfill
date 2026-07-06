<?php

namespace ImapPolyfill\Tests\Integration;

class ImapCheckTest extends GreenmailTestCase
{
    public function test_returns_mailbox_properties(): void
    {
        $folderName = 'CheckBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_check($connection);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame('imap', $result->Driver);
        // c-client normalizes the mailbox string (FQDN, appended /user="...")
        // rather than echoing the input verbatim, so only check the folder survives.
        $this->assertStringContainsString($folderName, $result->Mailbox);
        $this->assertSame(0, $result->Nmsgs);
        $this->assertSame(0, $result->Recent);
        $this->assertIsString($result->Date);
    }
}
