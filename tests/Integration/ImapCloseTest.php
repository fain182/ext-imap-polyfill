<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapCloseTest extends GreenmailTestCase
{
    public function test_closes_the_connection_and_returns_true(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);

        $result = imap_close($connection);

        $this->assertTrue($result);
        $this->expectException(\ValueError::class);
        imap_check($connection);
    }

    public function test_accepts_cl_expunge_flag(): void
    {
        $connection = imap_open(self::mailboxSpec(), self::USER, self::PASSWORD);

        $result = imap_close($connection, CL_EXPUNGE);

        $this->assertTrue($result);
    }
}
