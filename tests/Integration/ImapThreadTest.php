<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\CapturesWarnings;
use PHPUnit\Framework\Attributes\Group;

final class ImapThreadTest extends GreenmailTestCase
{
    use CapturesWarnings;

    public function test_reference_chain_nests_replies_and_keeps_unrelated_message_as_sibling(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $client = $this->makeFolder($folderName);
        $folder = $client->getFolder($folderName);
        $folder->appendMessage("Message-ID: <root@example.com>\r\nSubject: Original\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nRoot");
        $folder->appendMessage("Message-ID: <reply1@example.com>\r\nIn-Reply-To: <root@example.com>\r\nReferences: <root@example.com>\r\nSubject: Re: Original\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nReply1");
        $folder->appendMessage("Message-ID: <reply2@example.com>\r\nIn-Reply-To: <reply1@example.com>\r\nReferences: <root@example.com> <reply1@example.com>\r\nSubject: Re: Original\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\nReply2");
        $folder->appendMessage("Message-ID: <unrelated@example.com>\r\nSubject: Something else\r\nDate: Tue, 07 Jul 2026 08:00:00 +0000\r\n\r\nUnrelated");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame([
            '0.num' => 4, '0.next' => 0, '0.branch' => 1,
            '1.num' => 1, '1.next' => 2,
            '2.num' => 2, '2.next' => 3,
            '3.num' => 3, '3.next' => 0, '3.branch' => 0,
            '2.branch' => 0,
            '1.branch' => 0,
        ], imap_thread($connection));

        imap_close($connection);
    }

    public function test_matching_base_subject_groups_replies_without_explicit_references(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $client = $this->makeFolder($folderName);
        $folder = $client->getFolder($folderName);
        $folder->appendMessage("Message-ID: <a@example.com>\r\nSubject: Shared Topic\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nA");
        $folder->appendMessage("Message-ID: <b@example.com>\r\nSubject: Re: Shared Topic\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nB");
        $folder->appendMessage("Message-ID: <c@example.com>\r\nSubject: Fwd: Shared Topic\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\nC");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame([
            '0.num' => 1, '0.next' => 1,
            '1.num' => 2, '1.next' => 0, '1.branch' => 2,
            '2.num' => 3, '2.next' => 0, '2.branch' => 0,
            '0.branch' => 0,
        ], imap_thread($connection));

        imap_close($connection);
    }

    /**
     * c-client's base subject, not RFC 5256's: once whitespace has been
     * collapsed, the "(fwd)" trailer is no longer stripped. Greenmail has no
     * THREAD, so this runs c-client's own algorithm; Dovecot threads on the
     * server, by the RFC.
     */
    #[Group('greenmail-only')]
    public function test_a_fwd_trailer_survives_when_the_subject_had_whitespace_to_collapse(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $client = $this->makeFolder($folderName);
        $folder = $client->getFolder($folderName);
        $folder->appendMessage("Message-ID: <a@example.com>\r\nSubject: Hello world\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nA");
        $folder->appendMessage("Message-ID: <b@example.com>\r\nSubject: Hello  world (fwd)\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nB");
        $folder->appendMessage("Message-ID: <c@example.com>\r\nSubject: Hello world (fwd)\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\nC");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame([
            '0.num' => 1, '0.next' => 1,
            '1.num' => 3, '1.next' => 0, '1.branch' => 0,
            '0.branch' => 2,
            '2.num' => 2, '2.next' => 0, '2.branch' => 0,
        ], imap_thread($connection));

        imap_close($connection);
    }

    /**
     * References are read as route-addresses, not split on whitespace: two
     * ids with nothing between them are both read, a comma ends the list,
     * spaces inside the brackets do not matter, and an id with no brackets
     * is no id. Greenmail has no THREAD, so this is c-client's own parser.
     */
    #[Group('greenmail-only')]
    public function test_references_are_parsed_as_rfc822_message_ids(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $folder = $this->makeFolder($folderName)->getFolder($folderName);
        $folder->appendMessage("Message-ID: <a@example.com>\r\nSubject: One\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nA");
        $folder->appendMessage("Message-ID: <b@example.com>\r\nReferences: < a @ example.com >\r\nSubject: Two\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nB");
        $folder->appendMessage("Message-ID: <c@example.com>\r\nReferences: <a@example.com><b@example.com>\r\nSubject: Three\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\nC");
        $folder->appendMessage("Message-ID: <d@example.com>\r\nReferences: <a@example.com>,<c@example.com>\r\nSubject: Four\r\nDate: Tue, 07 Jul 2026 12:00:00 +0000\r\n\r\nD");
        $folder->appendMessage("Message-ID: <e@example.com>\r\nReferences: a@example.com\r\nSubject: Five\r\nDate: Tue, 07 Jul 2026 13:00:00 +0000\r\n\r\nE");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame(['a' => null, 'b' => 'a', 'c' => 'b', 'd' => 'a', 'e' => null], self::parents(imap_thread($connection), ['a', 'b', 'c', 'd', 'e']));

        imap_close($connection);
    }

    /**
     * c-client reads an IMAP message's references from the overview, where
     * a message without References has none: In-Reply-To is not consulted.
     */
    #[Group('greenmail-only')]
    public function test_in_reply_to_is_not_read_over_imap(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $folder = $this->makeFolder($folderName)->getFolder($folderName);
        $folder->appendMessage("Message-ID: <a@example.com>\r\nSubject: One\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nA");
        $folder->appendMessage("Message-ID: <b@example.com>\r\nIn-Reply-To: <a@example.com>\r\nSubject: Two\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nB");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame(['a' => null, 'b' => null], self::parents(imap_thread($connection), ['a', 'b']));

        imap_close($connection);
    }

    /**
     * Over POP3 c-client reads the envelope instead, and falls back to
     * In-Reply-To where References gives no id — but only to its first id.
     * Asserted on the seeded messages alone, the POP3 INBOX being shared.
     */
    public function test_pop3_falls_back_to_the_first_in_reply_to_id(): void
    {
        $tag = (string) random_int(10000, 99999);
        $folder = self::seedClient()->getFolder('INBOX');
        $folder->appendMessage("Message-ID: <a{$tag}@example.com>\r\nSubject: One {$tag}\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nA");
        $folder->appendMessage("Message-ID: <b{$tag}@example.com>\r\nSubject: Two {$tag}\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nB");
        $folder->appendMessage("Message-ID: <c{$tag}@example.com>\r\nIn-Reply-To: <a{$tag}@example.com> <b{$tag}@example.com>\r\nSubject: Three {$tag}\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\nC");
        $folder->appendMessage("Message-ID: <d{$tag}@example.com>\r\nReferences: garbage\r\nIn-Reply-To: <b{$tag}@example.com>\r\nSubject: Four {$tag}\r\nDate: Tue, 07 Jul 2026 12:00:00 +0000\r\n\r\nD");

        $connection = imap_open(self::pop3MailboxSpec(), self::user(), self::password());
        $count = imap_num_msg($connection);
        $names = [$count - 3 => 'a', $count - 2 => 'b', $count - 1 => 'c', $count => 'd'];

        $this->assertSame(['a' => null, 'b' => null, 'c' => 'a', 'd' => 'b'], self::parents(imap_thread($connection), $names));

        imap_close($connection);
    }

    /**
     * Each named message's parent, by name: null for a message at the top,
     * or under a node that is no message (the dummy grouping a subject).
     *
     * @param array<string, int> $tree
     * @param array<int, string> $names msgno => name; a list names 1..n
     *
     * @return array<string, ?string>
     */
    private static function parents(array $tree, array $names): array
    {
        if (array_is_list($names)) {
            $names = array_combine(range(1, count($names)), $names);
        }

        $parent = [];
        foreach ($tree as $key => $value) {
            [$node, $field] = explode('.', $key);
            if ($field === 'next' && $value !== 0) {
                for ($child = $value; $child !== 0; $child = $tree[$child.'.branch']) {
                    $parent[$tree[$child.'.num']] = $tree[$node.'.num'];
                }
            }
        }

        $result = [];
        foreach ($names as $msgno => $name) {
            $result[$name] = $names[$parent[$msgno] ?? 0] ?? null;
        }

        return $result;
    }

    public function test_duplicate_message_ids_each_keep_their_own_node(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $client = $this->makeFolder($folderName);
        $folder = $client->getFolder($folderName);
        $folder->appendMessage("Message-ID: <dup@example.com>\r\nSubject: Alpha\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nFirst claimant");
        $folder->appendMessage("Message-ID: <dup@example.com>\r\nSubject: Beta\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nDuplicate");
        $folder->appendMessage("Message-ID: <r@example.com>\r\nReferences: <dup@example.com>\r\nSubject: Re: Alpha\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\nReply");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        // The first message claiming <dup@...> owns it (the reply threads
        // under it); the duplicate gets its own parentless node instead of
        // clobbering the first.
        $this->assertSame([
            '0.num' => 1, '0.next' => 1,
            '1.num' => 3, '1.next' => 0, '1.branch' => 0,
            '0.branch' => 2,
            '2.num' => 2, '2.next' => 0, '2.branch' => 0,
        ], imap_thread($connection));

        imap_close($connection);
    }

    public function test_a_messages_own_references_override_links_from_other_chains(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $client = $this->makeFolder($folderName);
        $folder = $client->getFolder($folderName);
        $folder->appendMessage("Message-ID: <t1@example.com>\r\nSubject: One\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nOne");
        // This chain claims t3 hangs under t1...
        $folder->appendMessage("Message-ID: <t2@example.com>\r\nReferences: <t1@example.com> <t3@example.com>\r\nSubject: Two\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nTwo");
        // ...but t3's own (empty) References are authoritative: it detaches
        // from t1 and roots its own thread (c-client step 1B).
        $folder->appendMessage("Message-ID: <t3@example.com>\r\nSubject: Three\r\nDate: Tue, 07 Jul 2026 11:00:00 +0000\r\n\r\nThree");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame([
            '0.num' => 1, '0.next' => 0, '0.branch' => 1,
            '1.num' => 3, '1.next' => 2,
            '2.num' => 2, '2.next' => 0, '2.branch' => 0,
            '1.branch' => 0,
        ], imap_thread($connection));

        imap_close($connection);
    }

    public function test_se_uid_returns_uids_instead_of_msgnos(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $client = $this->makeFolder($folderName);
        $folder = $client->getFolder($folderName);
        $folder->appendMessage("Message-ID: <x1@example.com>\r\nSubject: X\r\nDate: Tue, 07 Jul 2026 09:00:00 +0000\r\n\r\nX1");
        $folder->appendMessage("Message-ID: <x2@example.com>\r\nIn-Reply-To: <x1@example.com>\r\nReferences: <x1@example.com>\r\nSubject: Re: X\r\nDate: Tue, 07 Jul 2026 10:00:00 +0000\r\n\r\nX2");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $msgnoTree = imap_thread($connection);
        $uidTree = imap_thread($connection, SE_UID);

        $this->assertSame($msgnoTree, $uidTree);

        imap_close($connection);
    }

    public function test_empty_mailbox_returns_false(): void
    {
        $folderName = 'ImapThread'.random_int(10000, 99999);
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        $this->assertSame(
            [false, ['imap_thread(): Function returned an empty tree']],
            $this->capturingWarnings(static fn () => imap_thread($connection)),
        );

        imap_close($connection);
    }

    public function test_a_failed_thread_warns_the_same_way(): void
    {
        $connection = $this->openConnectionToFolderThatThenDisappears('ImapThread'.random_int(10000, 99999));

        $this->assertSame(
            [false, ['imap_thread(): Function returned an empty tree']],
            $this->capturingWarnings(static fn () => imap_thread($connection)),
        );
    }
}
