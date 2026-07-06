<?php

namespace Fain182\ImapPolyfill\Tests\Integration;

use Fain182\ImapPolyfill\Tests\ResetsErrorStack;

/**
 * Covers the catch(\Throwable)->ErrorStack->return-false path shared by
 * nearly every wrapper function, plus a few argument-validation edge cases.
 * These paths were previously completely untested (only imap_open's own
 * failure path had coverage), found via a code coverage pass.
 */
class ImapErrorPathsTest extends GreenmailTestCase
{
    use ResetsErrorStack;

    public function test_imap_num_msg_returns_the_cached_count_when_the_folder_disappears(): void
    {
        // ext-imap's imap_num_msg reads a client-cached count (c-client's
        // stream->nmsgs), not a live query, so it keeps returning the last
        // known value instead of false once the connection breaks.
        $connection = $this->openConnectionToFolderThatThenDisappears('NumMsgErrBox'.uniqid());

        // No error is recorded either: a cached read never talks to the
        // server, so it has no way to notice anything is wrong.
        $this->assertSame(0, imap_num_msg($connection));
    }

    public function test_imap_check_returns_false_when_the_folder_disappears(): void
    {
        $connection = $this->openConnectionToFolderThatThenDisappears('CheckErrBox'.uniqid());

        $this->assertFalse(imap_check($connection));
    }

    public function test_imap_search_returns_false_when_the_folder_disappears(): void
    {
        $connection = $this->openConnectionToFolderThatThenDisappears('SearchErrBox'.uniqid());

        $this->assertFalse(imap_search($connection, 'ALL'));
    }

    public function test_imap_fetchheader_returns_false_when_the_folder_disappears(): void
    {
        $connection = $this->openConnectionToFolderThatThenDisappears('FetchHeaderErrBox'.uniqid());

        $this->assertFalse(imap_fetchheader($connection, 1));
    }

    public function test_imap_headerinfo_returns_false_when_the_folder_disappears(): void
    {
        $connection = $this->openConnectionToFolderThatThenDisappears('HeaderinfoErrBox'.uniqid());

        $this->assertFalse(imap_headerinfo($connection, 1));
    }

    public function test_imap_fetch_overview_returns_empty_array_when_the_folder_disappears(): void
    {
        // Observed real ext-imap behavior: an empty result set, not false.
        $connection = $this->openConnectionToFolderThatThenDisappears('OverviewErrBox'.uniqid());

        $this->assertSame([], imap_fetch_overview($connection, '1:1'));
    }

    public function test_imap_fetch_overview_returns_empty_array_for_an_empty_sequence(): void
    {
        $folderName = 'OverviewEmptyBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        // No messages exist, so "1:*" resolves to an empty id list.
        $this->assertSame([], imap_fetch_overview($connection, '1:*'));
    }

    public function test_imap_fetchstructure_returns_false_when_the_folder_disappears(): void
    {
        $connection = $this->openConnectionToFolderThatThenDisappears('StructErrBox'.uniqid());

        $this->assertFalse(imap_fetchstructure($connection, 1));
    }

    public function test_imap_fetchbody_returns_false_when_the_folder_disappears(): void
    {
        $connection = $this->openConnectionToFolderThatThenDisappears('FetchBodyErrBox'.uniqid());

        $this->assertFalse(imap_fetchbody($connection, 1, '1'));
    }

    public function test_imap_list_returns_false_when_nothing_matches(): void
    {
        $folderName = 'ListErrBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertFalse(imap_list($connection, self::mailboxSpec(''), 'NoSuchFolderXYZ*'));
    }

    public function test_imap_getmailboxes_returns_false_when_nothing_matches(): void
    {
        $folderName = 'GetMboxErrBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertFalse(imap_getmailboxes($connection, self::mailboxSpec(''), 'NoSuchFolderXYZ*'));
    }

    public function test_imap_list_returns_false_on_a_protocol_error(): void
    {
        $folderName = 'ListProtoErrBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        // Greenmail rejects a '*' that isn't the last character of the pattern.
        $this->assertFalse(imap_list($connection, self::mailboxSpec(''), 'No*Such'));
        $this->assertIsString(imap_last_error());
    }

    public function test_imap_getmailboxes_returns_false_on_a_protocol_error(): void
    {
        $folderName = 'GetMboxProtoErrBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertFalse(imap_getmailboxes($connection, self::mailboxSpec(''), 'No*Such'));
    }

    public function test_imap_expunge_survives_the_folder_disappearing(): void
    {
        $connection = $this->openConnectionToFolderThatThenDisappears('ExpungeErrBox'.uniqid());

        // imap_expunge/imap_setflag_full always return true per ext-imap's own
        // contract, even when the underlying operation failed — the failure
        // is only observable via imap_last_error().
        $this->assertTrue(imap_expunge($connection));
        $this->assertIsString(imap_last_error());
    }

    public function test_imap_setflag_full_survives_the_folder_disappearing(): void
    {
        $connection = $this->openConnectionToFolderThatThenDisappears('SetFlagErrBox'.uniqid());

        $this->assertTrue(imap_setflag_full($connection, '1', '\\Seen'));
        $this->assertIsString(imap_last_error());
    }

    public function test_imap_append_returns_false_for_a_nonexistent_folder(): void
    {
        $folderName = 'AppendErrBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $result = imap_append($connection, self::mailboxSpec('NoSuchFolder'.uniqid()), "Subject: Hi\r\n\r\nBody");

        $this->assertFalse($result);
    }

    public function test_imap_uid_returns_false_when_the_folder_disappears(): void
    {
        $connection = $this->openConnectionToFolderThatThenDisappears('UidConnErrBox'.uniqid());

        $this->assertFalse(imap_uid($connection, 1));
    }

    public function test_imap_msgno_returns_zero_when_the_folder_disappears(): void
    {
        $connection = $this->openConnectionToFolderThatThenDisappears('MsgnoConnErrBox'.uniqid());

        $this->assertSame(0, imap_msgno($connection, 1));
    }

    public function test_imap_uid_throws_value_error_for_non_positive_message_number(): void
    {
        $folderName = 'UidErrBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        imap_uid($connection, 0);
    }

    public function test_imap_msgno_throws_value_error_for_non_positive_uid(): void
    {
        $folderName = 'MsgnoErrBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        imap_msgno($connection, -1);
    }
}
