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

    /** @param array<int, int> $byMsgno msgno => uid */
    public function __construct(private readonly array $byMsgno)
    {
        $this->uids = array_values($byMsgno);
        $this->msgnos = array_flip($byMsgno);
    }

    /**
     * The uid of a message the folder holds; asking for one past the end is
     * the caller's mistake, since the count is checked before the table.
     */
    public function uidOf(int $msgno): int
    {
        return $this->byMsgno[$msgno]
            ?? throw new \LogicException("no message {$msgno} in a folder of ".count($this->byMsgno));
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

    /**
     * What "*" stands for in a uid set: 0 where the folder has no messages.
     * Read with max() rather than off the end, since nothing in RFC 3501
     * makes a server answer a FETCH in message-number order.
     */
    public function highest(): int
    {
        return $this->uids === [] ? 0 : max($this->uids);
    }
}
