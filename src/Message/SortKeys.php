<?php

namespace ImapPolyfill\Message;

/**
 * One message's entry in c-client's sort cache (CH_SORTCACHE): every key
 * imap_sort() can order by, worked out once and kept for as long as the
 * message is, which is what makes a second sort of the same folder cost
 * nothing. The envelope keys are absent until a sort needed them, as
 * imap_sort() fetches envelopes only for a criterion that reads one.
 */
final class SortKeys
{
    private function __construct(
        private readonly int $arrival,
        private readonly int $size,
        private readonly ?EnvelopeSortKeys $envelope,
    ) {
    }

    public static function fast(string $internalDate, int $size): self
    {
        return new self(
            (int) SortKey::resolve(SortCriterion::Arrival, null, $internalDate, $size),
            $size,
            null,
        );
    }

    public static function withEnvelope(Envelope $envelope, string $internalDate, int $size): self
    {
        return new self(
            (int) SortKey::resolve(SortCriterion::Arrival, null, $internalDate, $size),
            $size,
            EnvelopeSortKeys::of($envelope, $internalDate, $size),
        );
    }

    public function serves(SortCriterion $criterion): bool
    {
        return !$criterion->needsEnvelope() || $this->envelope !== null;
    }

    public function key(SortCriterion $criterion): int|string
    {
        return match ($criterion) {
            SortCriterion::Arrival => $this->arrival,
            SortCriterion::Size => $this->size,
            SortCriterion::Date => $this->envelope($criterion)->date,
            SortCriterion::From => $this->envelope($criterion)->from,
            SortCriterion::To => $this->envelope($criterion)->to,
            SortCriterion::Cc => $this->envelope($criterion)->cc,
            SortCriterion::Subject => $this->envelope($criterion)->subject,
        };
    }

    private function envelope(SortCriterion $criterion): EnvelopeSortKeys
    {
        return $this->envelope
            ?? throw new \LogicException("Sort by {$criterion->name} asked of keys loaded without the envelope");
    }
}
