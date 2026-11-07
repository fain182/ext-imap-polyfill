<?php

namespace ImapPolyfill\Connection;

/**
 * The uids of the messages a folder holds, in the order the folder holds
 * them — c-client's per-mailbox uid table, which is what a uid set is read
 * against and what says which message a uid names.
 */
final class UidTable
{
    /** @var array<int, int> uid => msgno */
    private array $msgnos;

    /** @param array<int, int> $byMsgno msgno => uid */
    public function __construct(private readonly array $byMsgno)
    {
        $this->msgnos = array_flip($byMsgno);
    }

    /**
     * @throws MessageNotFoundException
     */
    public function uidOf(int $msgno): int
    {
        return $this->byMsgno[$msgno] ?? throw new MessageNotFoundException("Message {$msgno} not found");
    }

    /** @return array<int, int> msgno => uid, in message-number order */
    public function byMsgno(): array
    {
        return $this->byMsgno;
    }

    /** @return list<int> */
    public function uids(): array
    {
        return array_values($this->byMsgno);
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
        return $this->byMsgno === [] ? 0 : max($this->byMsgno);
    }
}
