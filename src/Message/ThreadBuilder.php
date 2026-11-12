<?php

namespace ImapPolyfill\Message;

/**
 * Client-side implementation of RFC5256's THREAD=REFERENCES algorithm,
 * for drivers (POP3, or any IMAP server without the THREAD extension —
 * e.g. Greenmail) that have no wire THREAD command. This mirrors what
 * c-client itself falls back to in that case (mail_thread_references in
 * mail.c), verified against real ext-imap via `make parity`.
 *
 * @see BaseSubject
 */
final class ThreadBuilder
{
    /**
     * Extracts the fields build() needs (Message-ID, references, base
     * subject inputs, date) out of a raw UID/INTERNALDATE/RFC822.HEADER
     * wire fetch — the same "Mailbox fetches, Message/* interprets" split
     * every other imap_* builder in this namespace follows.
     *
     * Where the references come from depends on the driver, as it does in
     * c-client. One with an overview (IMAP) fills the sort cache from it in
     * mail_thread_loadcache(), which leaves an empty list where there is no
     * References header — so In-Reply-To is never read. One without (POP3)
     * reads the envelope, falling back to the first In-Reply-To id when
     * References yields none.
     *
     * @param array<int, array<string, mixed>> $data msgno => wire fetch data
     * @param int[] $ids msgnos to include, in order
     * @param bool $fromOverview whether the driver has an overview
     *
     * @return array<int, array{msgno: int, uid: int, messageId: ?string, refs: string[], subject: string, date: int}>
     */
    public static function messagesFromFetch(array $data, array $ids, bool $fromOverview): array
    {
        $messages = [];

        foreach ($ids as $msgno) {
            if (!isset($data[$msgno])) {
                continue;
            }

            $message = $data[$msgno];
            $fields = RawHeaderFields::parse($message['RFC822.HEADER'])->toArray();
            $refs = MessageId::all($fields['references'] ?? '');

            if ($refs === [] && !$fromOverview) {
                $first = MessageId::first($fields['in-reply-to'] ?? '');
                $refs = $first === null ? [] : [$first];
            }

            $messages[] = [
                'msgno' => $msgno,
                'uid' => (int) $message['UID'],
                'messageId' => MessageId::first($fields['message-id'] ?? ''),
                'refs' => $refs,
                'subject' => $fields['subject'] ?? '',
                'date' => strtotime($fields['date'] ?? '') ?: (strtotime($message['INTERNALDATE']) ?: 0),
            ];
        }

        return $messages;
    }

    /**
     * @param array<int, array{msgno: int, uid: int, messageId: ?string, refs: string[], subject: string, date: int}> $messages
     *
     * @return ThreadContainer[] the pruned, sorted, subject-grouped root-level forest
     */
    public static function build(array $messages): array
    {
        $byId = [];
        $all = [];

        // c-client's "Step 1 (preliminary)": every message gets its own
        // container up front, so a reference never resolves to a message
        // appearing later under a duplicated ID. The first message claiming
        // a Message-ID owns it; later claimants get an anonymous container
        // (c-client keys those by a synthetic unique string nothing else
        // can reference).
        $containers = [];
        foreach ($messages as $index => $message) {
            $id = $message['messageId'];
            $containers[$index] = self::containerFor(
                $id !== null && isset($byId[$id]) ? null : $id,
                $byId,
                $all,
            );
        }

        foreach ($messages as $index => $message) {
            $container = $containers[$index];
            $container->msgno = $message['msgno'];
            $container->uid = $message['uid'];
            $container->date = $message['date'];
            $container->baseSubject = BaseSubject::of($message['subject']);
            $container->isReply = BaseSubject::isReplyOrForward($message['subject']);

            $refParent = null;
            foreach ($message['refs'] as $refId) {
                $refContainer = self::containerFor($refId, $byId, $all);

                if ($refParent !== null) {
                    self::link($refParent, $refContainer);
                }

                $refParent = $refContainer;
            }

            // Step 1B: the message's own References decide its parent — a
            // link set earlier by another message's reference chain is
            // broken, even when this message has no references at all.
            if ($container->parent !== null && $container->parent !== $refParent) {
                self::unlink($container);
            }
            if ($refParent !== null) {
                self::link($refParent, $container);
            }
        }

        $root = array_values(array_filter($all, static fn (ThreadContainer $c): bool => $c->parent === null));
        $root = self::prune($root, isRoot: true);
        $root = self::groupBySubject($root);
        self::sortRecursive($root);

        return $root;
    }

    /**
     * Flattens a root-level forest into ext-imap's ".num"/".next"/".branch"
     * shape, matching php_imap.c's build_thread_tree_helper exactly
     * (including its interleaved key insertion order).
     *
     * @param ThreadContainer[] $root
     *
     * @return array<string, int>
     */
    public static function flatten(array $root, bool $byUid): array
    {
        $tree = [];
        $numNodes = 0;
        self::flattenSiblings($root, $tree, $numNodes, $byUid);

        return $tree;
    }

    /**
     * @param string|null $id
     * @param array<string, ThreadContainer> $byId
     * @param ThreadContainer[] $all
     */
    private static function containerFor(?string $id, array &$byId, array &$all): ThreadContainer
    {
        if ($id !== null && isset($byId[$id])) {
            return $byId[$id];
        }

        $container = new ThreadContainer();
        $all[] = $container;

        if ($id !== null) {
            $byId[$id] = $container;
        }

        return $container;
    }

    /**
     * Links $child under $parent unless the child already has a parent
     * (an earlier link wins) or doing so would introduce a cycle.
     */
    private static function link(ThreadContainer $parent, ThreadContainer $child): void
    {
        if ($child->parent !== null || $child === $parent) {
            return;
        }

        for ($ancestor = $parent; $ancestor !== null; $ancestor = $ancestor->parent) {
            if ($ancestor === $child) {
                return;
            }
        }

        $child->parent = $parent;
        $parent->children[] = $child;
    }

    private static function unlink(ThreadContainer $child): void
    {
        $parent = $child->parent;
        if ($parent === null) {
            return;
        }

        $index = array_search($child, $parent->children, true);
        if ($index !== false) {
            array_splice($parent->children, $index, 1);
        }
        $child->parent = null;
    }

    /**
     * RFC5256 step 3: delete childless dummies; splice a dummy's children
     * up to its own level, unless that would put more than one message
     * directly under the root (then the dummy is kept to hold them).
     *
     * @param ThreadContainer[] $nodes
     *
     * @return ThreadContainer[]
     */
    private static function prune(array $nodes, bool $isRoot): array
    {
        $result = [];

        foreach ($nodes as $node) {
            $node->children = self::prune($node->children, isRoot: false);

            if (!$node->isDummy()) {
                $result[] = $node;

                continue;
            }

            if ($node->children === []) {
                continue;
            }

            if ($isRoot && count($node->children) > 1) {
                $result[] = $node;

                continue;
            }

            foreach ($node->children as $child) {
                $child->parent = null;
                $result[] = $child;
            }
        }

        return $result;
    }

    /**
     * RFC5256 step 5: merges root-level threads whose base subject matches.
     *
     * @param ThreadContainer[] $root
     *
     * @return ThreadContainer[]
     */
    private static function groupBySubject(array $root): array
    {
        // Step 5B: pick one representative container per base subject. A
        // dummy's subject is its first child's (pruning guarantees a
        // root-level dummy has 2+ children, so it always has one). Mirrors
        // c-client's replacement rule: prefer a dummy, or a non-reply/
        // forward message, over whatever's currently in the table.
        $table = [];
        foreach ($root as $node) {
            $subject = $node->isDummy() ? $node->children[0]->baseSubject : $node->baseSubject;

            if ($subject === '') {
                continue;
            }

            $existing = $table[$subject] ?? null;

            if ($existing === null) {
                $table[$subject] = $node;
            } elseif (!$existing->isDummy() && ($node->isDummy() || (!$node->isReply && $existing->isReply))) {
                $table[$subject] = $node;
            }
        }

        // Step 5C: merge every root-level node into its subject's
        // representative. $original (frozen from step 5B) decides whether
        // a node *was* the chosen representative — $table is then mutated
        // live as dummies get created, so a later node with the same
        // subject merges into the dummy instead of the original pick.
        $original = $table;
        $result = [];
        // Tracks each result-array position by object identity, so a
        // representative's slot can be replaced with a wrapping dummy in
        // O(1) instead of scanning $result for it on every merge.
        $resultIndex = [];
        foreach ($root as $node) {
            $subject = $node->isDummy() ? $node->children[0]->baseSubject : $node->baseSubject;

            if ($subject === '' || $original[$subject] === $node) {
                // Only place a representative's own slot if nothing has
                // wrapped it in a dummy yet (which would have already been
                // placed in $result at this position).
                if ($subject === '' || $table[$subject] === $node) {
                    $resultIndex[spl_object_id($node)] = count($result);
                    $result[] = $node;
                }

                continue;
            }

            $representative = $table[$subject];

            if ($representative->isDummy() && $node->isDummy()) {
                foreach ($node->children as $child) {
                    $child->parent = $representative;
                    $representative->children[] = $child;
                }
            } elseif ($representative->isDummy()) {
                $node->parent = $representative;
                $representative->children[] = $node;
            } elseif ($node->isReply && !$representative->isReply) {
                $node->parent = $representative;
                $representative->children[] = $node;
            } else {
                $dummy = new ThreadContainer();
                $representative->parent = $dummy;
                $node->parent = $dummy;
                $dummy->children = [$representative, $node];
                $table[$subject] = $dummy;

                $index = $resultIndex[spl_object_id($representative)] ?? null;
                if ($index !== null) {
                    $result[$index] = $dummy;
                    $resultIndex[spl_object_id($dummy)] = $index;
                } else {
                    $resultIndex[spl_object_id($dummy)] = count($result);
                    $result[] = $dummy;
                }
            }
        }

        return $result;
    }

    /**
     * @param ThreadContainer[] $nodes
     */
    private static function sortRecursive(array &$nodes): void
    {
        usort($nodes, static fn (ThreadContainer $a, ThreadContainer $b): int => $a->date <=> $b->date);

        foreach ($nodes as $node) {
            self::sortRecursive($node->children);
        }
    }

    /**
     * Rebuilds the container tree from the nested id groups of a server-side
     * THREAD response, so the result flattens through exactly the same code
     * as the local threader's.
     *
     * @param array<int, mixed> $groups
     *
     * @return ThreadContainer[]
     */
    public static function containersFromServer(array $groups, bool $byUid): array
    {
        $roots = [];
        foreach ($groups as $group) {
            $root = self::containerGroup(is_array($group) ? $group : [$group], $byUid);

            if ($root !== null) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    /**
     * RFC 5256 §4: inside a thread, consecutive ids are a parent/child chain,
     * and a nested list is a set of branches hanging off the id before it.
     *
     * @param array<int, mixed> $group
     */
    private static function containerGroup(array $group, bool $byUid): ?ThreadContainer
    {
        $root = null;
        $current = null;

        foreach ($group as $element) {
            if (is_array($element)) {
                $child = self::containerGroup($element, $byUid);

                if ($child === null) {
                    continue;
                }

                if ($current === null) {
                    // A thread opening on a nested list has no message of its
                    // own at the root; c-client stands a dummy node in for it.
                    $root = $current = new ThreadContainer();
                }

                $child->parent = $current;
                $current->children[] = $child;

                continue;
            }

            $node = new ThreadContainer();
            if ($byUid) {
                $node->uid = (int) $element;
            } else {
                $node->msgno = (int) $element;
            }

            if ($current === null) {
                $root = $node;
            } else {
                $node->parent = $current;
                $current->children[] = $node;
            }

            $current = $node;
        }

        return $root;
    }

    /**
     * php_imap.c's build_thread_tree_helper over c-client's THREADNODE list,
     * where ->next is the *child* pointer and ->branch the *sibling* one
     * (mail_thread_c2node()/mail_thread_sort() in mail.c, and `make parity`).
     * The output keys inherit that swapped meaning.
     *
     * The helper recurses on both pointers. Here siblings are a loop and only
     * children recurse: a folder's top-level threads are one sibling list, as
     * long as the folder has threads, and a PHP recursion that deep exhausts
     * the C stack where the extension's small C frames do not. The
     * branch is the helper's tail call, so the loop writes the keys and
     * numbers the nodes in the same order.
     *
     * @param ThreadContainer[] $siblings
     * @param array<string, int> $tree
     */
    private static function flattenSiblings(array $siblings, array &$tree, int &$numNodes, bool $byUid): void
    {
        $last = count($siblings) - 1;

        foreach (array_values($siblings) as $position => $container) {
            $thisNode = $numNodes;
            // A dummy container kept as the parent of a subject group has no
            // message; c-client's THREADNODE carries num 0 for it.
            $tree["{$thisNode}.num"] = ($byUid ? $container->uid : $container->msgno) ?? 0;

            if ($container->children !== []) {
                $numNodes++;
                $tree["{$thisNode}.next"] = $numNodes;
                self::flattenSiblings($container->children, $tree, $numNodes, $byUid);
            } else {
                $tree["{$thisNode}.next"] = 0;
            }

            if ($position < $last) {
                $numNodes++;
                $tree["{$thisNode}.branch"] = $numNodes;
            } else {
                $tree["{$thisNode}.branch"] = 0;
            }
        }
    }
}
