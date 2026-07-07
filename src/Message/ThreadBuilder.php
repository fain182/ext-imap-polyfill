<?php

namespace ImapPolyfill\Message;

/**
 * Client-side implementation of RFC5256's THREAD=REFERENCES algorithm,
 * for drivers (POP3, or any IMAP server without the THREAD extension —
 * e.g. Greenmail) that have no wire THREAD command. This mirrors what
 * c-client itself falls back to in that case, verified against real
 * ext-imap via `make parity`.
 *
 * Simplifications vs. the full RFC: duplicate Message-IDs across messages
 * aren't given synthetic unique IDs (the second message just doesn't get
 * its own container), and base-subject extraction (see BaseSubject) is a
 * practical subset of the full ABNF grammar.
 *
 * @see BaseSubject
 */
final class ThreadBuilder
{
    /**
     * @param array<int, array{msgno: int, uid: int, messageId: ?string, refs: string[], subject: string, date: int}> $messages
     *
     * @return ThreadContainer[] the pruned, sorted, subject-grouped root-level forest
     */
    public static function build(array $messages): array
    {
        $byId = [];
        $all = [];

        foreach ($messages as $message) {
            $container = self::containerFor($message['messageId'], $byId, $all);
            $container->msgno = $message['msgno'];
            $container->uid = $message['uid'];
            $container->date = $message['date'];
            $container->baseSubject = BaseSubject::of($message['subject']);
            $container->isReply = BaseSubject::isReplyOrForward($message['subject']);

            $parentId = null;
            foreach ($message['refs'] as $refId) {
                $refId = self::normalizeId($refId);
                if ($refId === null) {
                    continue;
                }

                $refContainer = self::containerFor($refId, $byId, $all);

                if ($parentId !== null) {
                    self::link(self::containerFor($parentId, $byId, $all), $refContainer);
                }

                $parentId = $refId;
            }

            if ($parentId !== null) {
                self::link(self::containerFor($parentId, $byId, $all), $container);
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
        $chain = self::chain($root);

        if ($chain === null) {
            return [];
        }

        $numNodes = 0;

        return self::flattenNode($chain, $numNodes, $byUid);
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

    private static function normalizeId(string $raw): ?string
    {
        $id = trim($raw, " \t<>");

        return $id === '' ? null : $id;
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
            $subject = $node->isDummy() ? ($node->children[0]->baseSubject ?? '') : $node->baseSubject;

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
        foreach ($root as $node) {
            $subject = $node->isDummy() ? ($node->children[0]->baseSubject ?? '') : $node->baseSubject;

            if ($subject === '' || $original[$subject] === $node) {
                // Only place a representative's own slot if nothing has
                // wrapped it in a dummy yet (which would have already been
                // placed in $result at this position).
                if ($subject === '' || $table[$subject] === $node) {
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

                $index = array_search($representative, $result, true);
                if ($index !== false) {
                    $result[$index] = $dummy;
                } else {
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
     * Converts a root-level forest into c-client's THREADNODE linked
     * representation — counter-intuitively, ->next is the *child* pointer
     * and ->branch is the *sibling* pointer (confirmed against c-client's
     * mail_thread_c2node()/mail_thread_sort() in mail.c, and against real
     * ext-imap's output via `make parity`); php_imap.c's build_thread_tree
     * walks these two fields as-is, so the polyfill's own ".num"/".next"/
     * ".branch" output keys inherit the same swapped meaning.
     *
     * @param ThreadContainer[] $siblings
     */
    private static function chain(array $siblings): ?object
    {
        $nodes = array_map(static fn (ThreadContainer $c): object => (object) [
            'num' => $c->msgno,
            'uid' => $c->uid,
            'next' => self::chain($c->children),
            'branch' => null,
        ], $siblings);

        for ($i = 0; $i < count($nodes) - 1; $i++) {
            $nodes[$i]->branch = $nodes[$i + 1];
        }

        return $nodes[0] ?? null;
    }

    /**
     * @return array<string, int>
     */
    private static function flattenNode(object $cur, int &$numNodes, bool $byUid): array
    {
        $thisNode = $numNodes;
        $tree = ["{$thisNode}.num" => ($byUid ? $cur->uid : $cur->num) ?? 0];

        if ($cur->next !== null) {
            $numNodes++;
            $tree["{$thisNode}.next"] = $numNodes;
            $tree += self::flattenNode($cur->next, $numNodes, $byUid);
        } else {
            $tree["{$thisNode}.next"] = 0;
        }

        if ($cur->branch !== null) {
            $numNodes++;
            $tree["{$thisNode}.branch"] = $numNodes;
            $tree += self::flattenNode($cur->branch, $numNodes, $byUid);
        } else {
            $tree["{$thisNode}.branch"] = 0;
        }

        return $tree;
    }
}
