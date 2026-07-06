<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapGetmailboxesTest extends GreenmailTestCase
{
    public function test_returns_mailbox_objects_with_name_attributes_and_delimiter(): void
    {
        $folderName = 'GetMboxBox' . uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec('INBOX'), self::USER, self::PASSWORD);

        $result = imap_getmailboxes($connection, self::mailboxSpec(''), '*');

        $this->assertIsArray($result);
        $names = array_map(static fn (\stdClass $m) => $m->name, $result);
        $this->assertContains(self::mailboxSpec($folderName), $names);

        $match = array_values(array_filter($result, static fn (\stdClass $m) => $m->name === self::mailboxSpec($folderName)))[0];
        $this->assertSame('.', $match->delimiter);
        $this->assertIsInt($match->attributes);
    }
}
