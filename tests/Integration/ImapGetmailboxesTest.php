<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

class ImapGetmailboxesTest extends GreenmailTestCase
{
    /**
     * Asserts the hierarchy delimiter and an exact folder set; Dovecot
     * uses '/' and ships Drafts, Junk, Sent and Trash.
     */
    #[Group('greenmail-only')]
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
