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

    public static function of(Envelope $envelope, string $internalDate, int $size): self
    {
        return new self(
            (int) SortKey::resolve(SortCriterion::Date, $envelope, $internalDate, $size),
            (string) SortKey::resolve(SortCriterion::From, $envelope, $internalDate, $size),
            (string) SortKey::resolve(SortCriterion::To, $envelope, $internalDate, $size),
            (string) SortKey::resolve(SortCriterion::Cc, $envelope, $internalDate, $size),
            (string) SortKey::resolve(SortCriterion::Subject, $envelope, $internalDate, $size),
        );
    }
}
