<?php

namespace ImapPolyfill\Tests\Integration;

class ImapListmailboxTest extends GreenmailTestCase
{
    public function test_is_an_alias_of_imap_list(): void
    {
        $folderName = 'ListAliasBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $reference = self::mailboxSpec('');

        $this->assertSame(
            imap_list($connection, $reference, $folderName),
            imap_listmailbox($connection, $reference, $folderName)
        );
    }
}
