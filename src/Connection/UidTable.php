<?php

namespace ImapPolyfill\Connection;

/**
 * The uids of the messages a folder holds, in the order the folder holds
 * them — c-client's per-mailbox uid table, which is what a uid set is read
 * against and what says which message a uid names.
 */
final class UidTable
{
    /** @var list<int> */
    private array $uids;

    /** @var array<int, int> uid => msgno */
    private array $msgnos;

    /** @param array<int, int> $byMsgno msgno => uid, as a backend reports it */
    public function __construct(array $byMsgno)
    {
        $this->uids = array_values($byMsgno);
        $this->msgnos = array_flip($byMsgno);
    }

    /** @return list<int> */
    public function uids(): array
    {
        return $this->uids;
    }

    public function holds(int $uid): bool
    {
        return isset($this->msgnos[$uid]);
    }

    public function msgnoOf(int $uid): int
    {
        return $this->msgnos[$uid];
    }

    /** What "*" stands for in a uid set: 0 where the folder has no messages. */
    public function highest(): int
    {
        return $this->uids === [] ? 0 : $this->uids[count($this->uids) - 1];
    }
}
