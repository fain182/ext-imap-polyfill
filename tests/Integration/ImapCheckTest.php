<?php

namespace ImapPolyfill\Tests\Integration;

class ImapCheckTest extends GreenmailTestCase
{
    public function test_returns_mailbox_properties(): void
    {
        $folderName = 'CheckBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $result = imap_check($connection);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame(['Date', 'Driver', 'Mailbox', 'Nmsgs', 'Recent'], array_keys(get_object_vars($result)));
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
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        // Everything but the host is pinned: c-client resolves the host to
        // its canonical DNS name (environment-dependent), the polyfill
        // reports it as given — the documented divergence.
        $this->assertMatchesRegularExpression(
            sprintf(
                '/^\{[^:}]+:%d%s\/user="%s"\}%s$/',
                self::port(),
                self::flagsPattern(),
                self::user(),
                preg_quote($folderName, '/')
            ),
            imap_check($connection)->Mailbox
        );
    }

    public function test_mailbox_property_includes_readonly_before_the_user(): void
    {
        $folderName = 'CheckBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password(), OP_READONLY);

        $this->assertMatchesRegularExpression(
            sprintf(
                '/^\{[^:}]+:%d%s\/readonly\/user="%s"\}%s$/',
                self::port(),
                self::flagsPattern(),
                self::user(),
                preg_quote($folderName, '/')
            ),
            imap_check($connection)->Mailbox
        );
    }

    /**
     * c-client's rfc822_date(): the day of the month unpadded, and the zone
     * name after the offset.
     */
    public function test_date_is_written_by_rfc822_date(): void
    {
        $folderName = 'DateBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $date = imap_check($connection)->Date;

        $this->assertMatchesRegularExpression(
            '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), [1-9]\d? [A-Z][a-z]{2} \d{4} \d\d:\d\d:\d\d [+-]\d{4} \(\S+\)$/',
            $date,
        );
    }
}
