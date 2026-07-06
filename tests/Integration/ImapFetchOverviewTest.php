<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

class ImapFetchOverviewTest extends GreenmailTestCase
{
    public function test_returns_overview_objects_for_the_sequence(): void
    {
        $folderName = 'OverviewBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage(
            "Subject: Hello World\r\n"
            ."From: Joe Doe <joe@example.com>\r\n"
            ."To: jane@example.com\r\n"
            ."Date: Mon, 6 Jul 2026 12:00:00 +0000\r\n"
            ."\r\n"
            ."Body text"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_fetch_overview($connection, '1:1');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);

        $overview = $result[0];
        $this->assertInstanceOf(\stdClass::class, $overview);
        $this->assertSame('Hello World', $overview->subject);
        $this->assertSame('Joe Doe <joe@example.com>', $overview->from);
        $this->assertSame('jane@example.com', $overview->to);
        $this->assertSame(1, $overview->msgno);
        $this->assertSame(1, $overview->uid);
        $this->assertSame(0, $overview->seen);
        $this->assertSame(0, $overview->flagged);
        $this->assertSame(0, $overview->answered);
        $this->assertSame(0, $overview->deleted);
        $this->assertSame(0, $overview->draft);
        $this->assertIsInt($overview->size);
        $this->assertIsInt($overview->udate);
    }
}
