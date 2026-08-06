<?php

namespace ImapPolyfill\Tests\Integration;

/**
 * imap_thread() against a server that advertises THREAD=REFERENCES, which
 * Greenmail does not: there, and only there, c-client threads locally.
 * The local threader itself is characterized in ImapThreadTest.
 */
final class DovecotThreadTest extends DovecotTestCase
{
    /**
     * Four messages, one thread of three plus an unrelated one. The tree is
     * the server's, so this asserts the shape ext-imap gets from the same
     * server rather than the local algorithm's output.
     */
    private function seedThread(): string
    {
        $folderName = 'DcThread'.random_int(10000, 99999);
        $folder = $this->makeFolder($folderName)->getFolder($folderName);
        $folder->appendMessage("Message-ID: <root@example.com>\r\nSubject: Original\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nRoot");
        $folder->appendMessage("Message-ID: <reply1@example.com>\r\nIn-Reply-To: <root@example.com>\r\nReferences: <root@example.com>\r\nSubject: Re: Original\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nReply1");
        $folder->appendMessage("Message-ID: <reply2@example.com>\r\nIn-Reply-To: <reply1@example.com>\r\nReferences: <root@example.com> <reply1@example.com>\r\nSubject: Re: Original\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\nReply2");
        $folder->appendMessage("Message-ID: <other@example.com>\r\nSubject: Unrelated\r\nDate: Tue, 07 Jul 2026 08:00:00 +0000\r\n\r\nOther");

        return $folderName;
    }

    public function test_threads_a_reply_chain(): void
    {
        $connection = imap_open(self::mailboxSpec($this->seedThread()), self::USER, self::PASSWORD);

        $tree = imap_thread($connection);

        $this->assertIsArray($tree);
        // Node 0 is the first thread's root, and every node carries the
        // .num/.next/.branch triple ext-imap's build_thread_tree() emits.
        $this->assertArrayHasKey('0.num', $tree);
        $this->assertArrayHasKey('0.next', $tree);
        $this->assertArrayHasKey('0.branch', $tree);

        $nums = [];
        foreach ($tree as $key => $value) {
            if (str_ends_with($key, '.num')) {
                $nums[] = $value;
            }
        }

        sort($nums);
        $this->assertSame([1, 2, 3, 4], $nums);

        imap_close($connection);
    }

    /**
     * The reply chain has to come back as a chain: each message is the
     * ".next" of the one before, not a sibling branch of it.
     */
    public function test_replies_hang_off_their_parent(): void
    {
        $connection = imap_open(self::mailboxSpec($this->seedThread()), self::USER, self::PASSWORD);

        $tree = imap_thread($connection);

        $root = self::nodeWithNum($tree, 1);
        $this->assertNotNull($root, 'the thread root should be in the tree');
        $this->assertSame(2, $tree[$root.'.next'] === 0 ? 0 : $tree[$tree[$root.'.next'].'.num']);

        imap_close($connection);
    }

    public function test_se_uid_reports_uids(): void
    {
        $folderName = $this->seedThread();
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $byMsgno = imap_thread($connection);
        $byUid = imap_thread($connection, SE_UID);

        $this->assertIsArray($byUid);
        // Same tree shape either way; only the ids differ, and on a folder
        // this fresh the uids happen to match the message numbers.
        $this->assertSame(array_keys($byMsgno), array_keys($byUid));

        imap_close($connection);
    }

    /**
     * @param array<string, int> $tree
     */
    private static function nodeWithNum(array $tree, int $num): ?int
    {
        foreach ($tree as $key => $value) {
            if (str_ends_with($key, '.num') && $value === $num) {
                return (int) strtok($key, '.');
            }
        }

        return null;
    }
}
