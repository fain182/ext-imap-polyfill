<?php

namespace ImapPolyfill\Message;

/**
 * A node in the RFC5256 REFERENCES threading algorithm's working tree: a
 * "container" for either a real message (num !== null) or a dummy standing
 * in for a Message-ID mentioned in a References header that no fetched
 * message actually has (num === null).
 */
final class ThreadContainer
{
    public ?int $msgno = null;

    public ?int $uid = null;

    public int $date = 0;

    public string $baseSubject = '';

    /** Whether the underlying message had a non-empty References/In-Reply-To (i.e. is itself a reply/forward). Meaningless for dummies. */
    public bool $isReply = false;

    public ?self $parent = null;

    /** @var self[] */
    public array $children = [];

    public function isDummy(): bool
    {
        return $this->msgno === null;
    }
}
