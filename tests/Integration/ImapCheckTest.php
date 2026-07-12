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
        $this->assertStringContainsString($folderName, $result->Mailbox);
        $this->assertSame(0, $result->Nmsgs);
        $this->assertSame(0, $result->Recent);
        $this->assertIsString($result->Date);
    }

    public function test_mailbox_property_is_normalized_with_port_driver_flags_and_user(): void
    {
        $folderName = 'CheckBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        // Everything but the host is pinned: c-client resolves the host to
        // its canonical DNS name (environment-dependent), the polyfill
        // reports it as given — the documented divergence.
        $this->assertMatchesRegularExpression(
            sprintf(
                '/^\{[^:}]+:%d\/imap\/novalidate-cert\/user="%s"\}%s$/',
                self::port(),
                self::USER,
                preg_quote($folderName, '/')
            ),
            imap_check($connection)->Mailbox
        );
    }

    public function test_mailbox_property_includes_readonly_before_the_user(): void
    {
        $folderName = 'CheckBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD, OP_READONLY);

        $this->assertMatchesRegularExpression(
            sprintf(
                '/^\{[^:}]+:%d\/imap\/novalidate-cert\/readonly\/user="%s"\}%s$/',
                self::port(),
                self::USER,
                preg_quote($folderName, '/')
            ),
            imap_check($connection)->Mailbox
        );
    }
}
