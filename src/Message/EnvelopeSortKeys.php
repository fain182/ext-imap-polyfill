<?php

namespace ImapPolyfill\Message;

/**
 * The part of a message's sort cache that only a fetched envelope can fill.
 */
final class EnvelopeSortKeys
{
    private function __construct(
        public readonly int $date,
        public readonly string $from,
        public readonly string $to,
        public readonly string $cc,
        public readonly string $subject,
    ) {
    }

    public static function of(Envelope $envelope, int $arrival): self
    {
        return new self(
            SortKey::date($envelope, $arrival),
            SortKey::mailbox($envelope->from()),
            SortKey::mailbox($envelope->to()),
            SortKey::mailbox($envelope->cc()),
            BaseSubject::of($envelope->subject ?? '')->text,
        );
    }
}
