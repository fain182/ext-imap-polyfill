<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

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

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

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

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

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

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $byMsgno = imap_fetch_overview($connection, '1:1');
        $byUid = imap_fetch_overview($connection, (string) $survivorUid, FT_UID);

        $this->assertSame('Survivor', $byMsgno[0]->subject);
        $this->assertEquals($byMsgno[0], $byUid[0]);
    }

    /**
     * "*" in a uid set is the last message's uid, not the message count —
     * the two only look alike on a folder nobody has deleted from.
     */
    public function test_a_star_in_a_uid_set_is_the_highest_uid(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'OverviewUidStarBox' . uniqid(),
            "Subject: Survivor\r\n\r\nKeep me"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $result = imap_fetch_overview($connection, '1:*', FT_UID);

        $this->assertCount(1, $result);
        $this->assertSame($survivorUid, $result[0]->uid);
        $this->assertSame('Survivor', $result[0]->subject);
    }

    /**
     * A uid range is not a walk over the numbers it spans: c-client walks
     * the mailbox and keeps what falls inside, so naming the whole uid
     * space costs a folder of one message exactly one message.
     */
    public function test_a_uid_range_wider_than_the_folder_answers_what_the_folder_holds(): void
    {
        [$folderName, $survivorUid] = $this->makeMsgnoUidMismatchFixture(
            'OverviewUidWideBox' . uniqid(),
            "Subject: Survivor\r\n\r\nKeep me"
        );

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $result = imap_fetch_overview($connection, '1:4294967295', FT_UID);

        $this->assertCount(1, $result);
        $this->assertSame($survivorUid, $result[0]->uid);
    }

    public function test_throws_value_error_for_an_invalid_flags_bitmask(): void
    {
        $folderName = 'OverviewValBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: Flags\r\n\r\nBody");
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_fetch_overview(): Argument #3 ($flags) must be FT_UID or 0');
        imap_fetch_overview($connection, '1', FT_PEEK);
    }

    /**
     * c-client checks the set against the count it holds before anything
     * goes out, and its refusals are worded apart: which number was wrong,
     * and whether it was wrong as a number or as a delimiter. All of it is
     * observable through the error stack, so all of it is pinned here.
     *
     * @return iterable<string, array{string, int, string|null}>
     */
    public static function sequences(): iterable
    {
        yield 'a number past the end' => ['4', 0, 'Sequence out of range'];
        yield 'zero is not a message' => ['0', 0, 'Sequence out of range'];
        yield 'zero opening a range' => ['0:2', 0, 'Sequence out of range'];
        yield 'the far end past the end' => ['2:99', 0, 'Sequence range invalid'];
        yield 'no digits at all' => ['x', 0, 'Syntax error in sequence'];
        yield 'a minus is not a digit' => ['-1', 0, 'Syntax error in sequence'];
        yield 'nothing where a delimiter belongs' => ['1 , 2', 0, 'Sequence syntax error'];
        yield 'a second colon' => ['1:2:3', 0, 'Sequence range syntax error'];
        yield 'a leading comma' => [',1', 0, 'Syntax error in sequence'];
        yield 'the empty set asks for nothing' => ['', 0, null];
        yield 'a trailing comma is allowed' => ['1,', 1, null];
        yield 'a reversed range is read as the range it means' => ['3:1', 3, null];
        yield 'a star opening a range' => ['*:1', 3, null];
    }

    #[DataProvider('sequences')]
    public function test_the_message_set_is_checked_the_way_c_client_checks_it(
        string $sequence,
        int $expectedCount,
        ?string $expectedError,
    ): void {
        $folderName = 'OverviewSeqBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $folder = $seedClient->getFolder($folderName);
        foreach (['A', 'B', 'C'] as $subject) {
            $folder->appendMessage("Subject: {$subject}\r\n\r\nBody");
        }

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());
        imap_errors();

        $overview = imap_fetch_overview($connection, $sequence);

        $this->assertCount($expectedCount, $overview);
        $this->assertSame($expectedError === null ? [] : [$expectedError], imap_errors() ?: []);
    }

    /**
     * A uid is not a position in the folder, so nothing is past the end of
     * it — only zero is refused, and the wording is its own.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function uidSequences(): iterable
    {
        yield 'zero is not a uid' => ['0', 'UID may not be zero'];
        yield 'zero opening a range' => ['0:2', 'UID may not be zero'];
        yield 'nothing where a delimiter belongs' => ['1 , 2', 'UID sequence syntax error'];
        yield 'a second colon' => ['1:2:3', 'UID sequence range syntax error'];
        yield 'no digits at all' => ['x', 'Syntax error in sequence'];
        yield 'a uid nobody has is simply absent' => ['99999', null];
    }

    #[DataProvider('uidSequences')]
    public function test_a_uid_set_is_checked_by_its_own_rules(string $sequence, ?string $expectedError): void
    {
        $folderName = 'OverviewUidSeqBox'.uniqid();
        $seedClient = $this->makeFolder($folderName);
        $seedClient->getFolder($folderName)->appendMessage("Subject: A\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());
        imap_errors();

        $this->assertSame([], imap_fetch_overview($connection, $sequence, FT_UID));
        $this->assertSame($expectedError === null ? [] : [$expectedError], imap_errors() ?: []);
    }

    /**
     * "*" has to stand for something. Over an empty folder a msgno set says
     * so; a uid set has no count to be short of and names nothing.
     */
    public function test_a_star_over_an_empty_folder(): void
    {
        $folderName = 'OverviewStarBox'.uniqid();
        $this->makeFolder($folderName);

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());
        imap_errors();

        $this->assertSame([], imap_fetch_overview($connection, '*'));
        $this->assertSame(['No messages, so no maximum message number'], imap_errors());

        $this->assertSame([], imap_fetch_overview($connection, '*', FT_UID));
        $this->assertSame([], imap_errors() ?: []);
    }
}
