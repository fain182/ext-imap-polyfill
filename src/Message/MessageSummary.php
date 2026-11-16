<?php

namespace ImapPolyfill\Message;

/**
 * What c-client fetches whenever it needs a message's envelope: imap_fetch()
 * with FT_NEEDENV asks for the uid, the envelope, the fast attributes and the
 * flags in one command, whichever of them the caller is about to read.
 */
final class MessageSummary
{
    /**
     * @param list<string> $flags
     */
    public function __construct(
        public readonly int $uid,
        public readonly array $flags,
        public readonly string $internalDate,
        public readonly int $size,
        public readonly Envelope $envelope,
    ) {
    }
}
