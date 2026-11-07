<?php

namespace ImapPolyfill\Connection;

/**
 * The selected folder's messages as c-client's stream cache holds them: one
 * entry per message number, each carrying its uid once that is known.
 *
 * Nothing here is ever invalidated, only edited, by the same events that
 * edit c-client's cache (mail_exists(), mail_expunged()): a selection starts
 * it over, EXISTS grows it with entries whose uid is not yet known, EXPUNGE
 * takes out the entry it names and closes the gap. That is what keeps
 * message number k and the uid held at k describing the same message
 * whatever arrived in between — a snapshot compared against the count
 * cannot tell an expunge followed by an append from nothing at all.
 */
final class MessageCache
{
    /** @var list<?int> uid per message, in message-number order; null where not yet known */
    private array $uids = [];

    private bool $counted = false;

    private int $recent = 0;

    /** What uidTable() last built, until an edit makes it stale. */
    private ?UidTable $table = null;

    public function reset(): void
    {
        $this->table = null;
        $this->uids = [];
        $this->counted = false;
        $this->recent = 0;
    }

    public function exists(int $count): void
    {
        $this->table = null;
        $this->counted = true;
        $held = count($this->uids);

        $this->uids = $count >= $held
            ? [...$this->uids, ...array_fill(0, $count - $held, null)]
            : array_slice($this->uids, 0, $count);
    }

    public function recent(int $count): void
    {
        $this->recent = $count;
    }

    public function expunged(int $msgno): void
    {
        // Between a reset and the new folder's EXISTS the cache describes no
        // folder at all, so an EXPUNGE still in flight for the one being left
        // has nothing here to take out.
        if (!$this->counted) {
            return;
        }

        if ($msgno < 1 || $msgno > count($this->uids)) {
            throw new \RuntimeException("Expunge of non-existent message {$msgno} of ".count($this->uids));
        }

        $this->table = null;
        array_splice($this->uids, $msgno - 1, 1);
    }

    /**
     * Null until the folder has reported how many messages it holds since
     * it was selected.
     */
    public function count(): ?int
    {
        return $this->counted ? count($this->uids) : null;
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
        $unknown = [];
        foreach ($this->uids as $index => $uid) {
            if ($uid === null) {
                $unknown[] = $index + 1;
            }
        }

        return $unknown;
    }

    public function learnUid(int $msgno, int $uid): void
    {
        if (array_key_exists($msgno - 1, $this->uids)) {
            $this->table = null;
            $this->uids[$msgno - 1] = $uid;
        }
    }

    /**
     * The folder's uid table, which needs every uid to be known. Built once
     * per state of the cache: imap_uid() is routinely called once per
     * message, and each call asks this.
     */
    public function uidTable(): UidTable
    {
        return $this->table ??= new UidTable($this->uidsByMsgno());
    }

    /**
     * msgno => uid for every message, which needs every uid to be known.
     *
     * @return array<int, int>
     */
    public function uidsByMsgno(): array
    {
        $byMsgno = [];
        foreach ($this->uids as $index => $uid) {
            if ($uid === null) {
                throw new \LogicException('uid of message '.($index + 1).' asked for before it was learned');
            }

            $byMsgno[$index + 1] = $uid;
        }

        return $byMsgno;
    }
}
