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

    public function test_returns_multiple_messages_for_a_range_and_a_comma_list(): void
    {
        $folderName = 'OverviewBox' . uniqid();
        $seedClient = $this->makeFolder($folderName);
        $folder = $seedClient->getFolder($folderName);
        $folder->appendMessage("Subject: One\r\n\r\nBody 1");
        $folder->appendMessage("Subject: Two\r\n\r\nBody 2");
        $folder->appendMessage("Subject: Three\r\n\r\nBody 3");

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $range = imap_fetch_overview($connection, '1:2');
        $this->assertCount(2, $range);
        $this->assertSame('One', $range[0]->subject);
        $this->assertSame('Two', $range[1]->subject);

        $list = imap_fetch_overview($connection, '1,3');
        $this->assertCount(2, $list);
        $this->assertSame('One', $list[0]->subject);
        $this->assertSame('Three', $list[1]->subject);
    }

    public function test_ft_uid_fetches_by_uid_when_it_diverges_from_msgno(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'OverviewUidBox' . uniqid(),
            "Subject: Survivor\r\n\r\nKeep me"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $byMsgno = imap_fetch_overview($connection, '1:1');
        $byUid = imap_fetch_overview($connection, (string) $survivorUid, FT_UID);

        $this->assertSame('Survivor', $byMsgno[0]->subject);
        $this->assertEquals($byMsgno[0], $byUid[0]);
    }
}
