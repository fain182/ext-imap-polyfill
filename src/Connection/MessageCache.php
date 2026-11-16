<?php

namespace ImapPolyfill\Connection;

use ImapPolyfill\Message\SortCriterion;
use ImapPolyfill\Message\SortKeys;

/**
 * The selected folder's messages as c-client's stream cache holds them: one
 * entry per message number, each carrying its uid and its sort keys once
 * those are known.
 *
 * Nothing here is ever invalidated, only edited, by the same events that
 * edit c-client's cache (mail_exists(), mail_expunged()): a selection starts
 * it over, EXISTS grows it with entries whose uid is not yet known, EXPUNGE
 * takes out the entry it names and closes the gap. That is what keeps
 * message number k and the uid held at k describing the same message
 * whatever arrived in between.
 */
final class MessageCache
{
    /** @var list<?int>|null uid per message, in message-number order, null where not yet known; null until a count arrives */
    private ?array $uids = null;

    /** @var list<?SortKeys> per message, in message-number order, beside $uids */
    private array $sortKeys = [];

    private int $recent = 0;

    /** What uidTable() last built, until an edit makes it stale. */
    private ?UidTable $table = null;

    public function reset(): void
    {
        $this->uids = null;
        $this->sortKeys = [];
        $this->recent = 0;
        $this->table = null;
    }

    public function exists(int $count): void
    {
        $held = $this->uids ?? [];

        if ($this->uids !== null && $count === count($held)) {
            return;
        }

        $this->table = null;
        $this->uids = $count >= count($held)
            ? array_pad($held, $count, null)
            : array_slice($held, 0, $count);
        $this->sortKeys = $count >= count($held)
            ? array_pad($this->sortKeys, $count, null)
            : array_slice($this->sortKeys, 0, $count);
    }

    public function recent(int $count): void
    {
        $this->recent = $count;
    }

    public function expunged(int $msgno): void
    {
        $held = count($this->uids ?? []);

        if ($this->uids === null || $msgno < 1 || $msgno > $held) {
            throw new \RuntimeException("Expunge of non-existent message {$msgno} of {$held}");
        }

        $this->table = null;
        array_splice($this->uids, $msgno - 1, 1);
        array_splice($this->sortKeys, $msgno - 1, 1);
    }

    /**
     * Null until the folder has reported how many messages it holds since
     * it was selected.
     */
    public function count(): ?int
    {
        return $this->uids === null ? null : count($this->uids);
    }

    public function recentCount(): int
    {
        return $this->recent;
    }

    /**
     * The message numbers whose uid is not yet known, lowest first.
     *
     * @return list<int>
     */
    public function unknownUids(): array
    {
        // A table is only ever built once every uid is known.
        if ($this->table !== null) {
            return [];
        }

        $unknown = [];
        foreach ($this->counted() as $index => $uid) {
            if ($uid === null) {
                $unknown[] = $index + 1;
            }
        }

        return $unknown;
    }

    /**
     * Null where the uid is not yet known, as for a message the folder does
     * not hold.
     */
    public function uid(int $msgno): ?int
    {
        return $this->counted()[$msgno - 1] ?? null;
    }

    public function learnUid(int $msgno, int $uid): void
    {
        if ($this->uids !== null && array_key_exists($msgno - 1, $this->uids)) {
            $this->table = null;
            $this->uids[$msgno - 1] = $uid;
        }
    }

    /**
     * Of these messages, the ones whose sort keys cannot yet answer for the
     * criterion — the sequence imap_sort() builds before it fetches.
     *
     * @param list<int> $msgnos
     *
     * @return list<int>
     */
    public function withoutSortKeys(array $msgnos, SortCriterion $criterion): array
    {
        $this->counted();

        return array_values(array_filter(
            $msgnos,
            fn (int $msgno): bool => !($this->sortKeys[$msgno - 1] ?? null)?->serves($criterion),
        ));
    }

    public function learnSortKeys(int $msgno, SortKeys $keys): void
    {
        if (array_key_exists($msgno - 1, $this->sortKeys)) {
            $this->sortKeys[$msgno - 1] = $keys;
        }
    }

    /**
     * Of these messages, the keys of the ones a fetch has answered for: one
     * the server left out is left out of the sort, as it was never in the
     * cache to sort.
     *
     * @param list<int> $msgnos
     *
     * @return array<int, SortKeys> by message number
     */
    public function sortKeys(array $msgnos): array
    {
        $keys = [];
        foreach ($msgnos as $msgno) {
            if (($known = $this->sortKeys[$msgno - 1] ?? null) !== null) {
                $keys[$msgno] = $known;
            }
        }

        return $keys;
    }

    /**
     * The folder's uid table, which needs every uid to be known. Built once
     * per state of the cache: imap_uid() is routinely called once per
     * message, and each call asks this.
     */
    public function uidTable(): UidTable
    {
        if ($this->table !== null) {
            return $this->table;
        }

        $byMsgno = [];
        foreach ($this->counted() as $index => $uid) {
            if ($uid === null) {
                throw new \LogicException('uid of message '.($index + 1).' asked for before it was learned');
            }

            $byMsgno[$index + 1] = $uid;
        }

        return $this->table = new UidTable($byMsgno);
    }

    /**
     * The entries, for a question that only has an answer once the folder
     * has said how many messages it holds. Before that there is no folder
     * to ask about, not an empty one — which is what reading null as []
     * would answer.
     *
     * @return list<?int>
     */
    private function counted(): array
    {
        return $this->uids ?? throw new \LogicException('the uids of a folder asked for before its message count arrived');
    }
}
