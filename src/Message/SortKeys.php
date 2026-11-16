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
    /**
     * @param array<string, int|string>|null $envelopeKeys by criterion name
     */
    private function __construct(
        private readonly int $arrival,
        private readonly int $size,
        private readonly ?array $envelopeKeys,
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
        $keys = [];
        foreach (SortCriterion::cases() as $criterion) {
            if ($criterion->needsEnvelope()) {
                $keys[$criterion->name] = SortKey::resolve($criterion, $envelope, $internalDate, $size);
            }
        }

        return new self(
            (int) SortKey::resolve(SortCriterion::Arrival, null, $internalDate, $size),
            $size,
            $keys,
        );
    }

    public function serves(SortCriterion $criterion): bool
    {
        return !$criterion->needsEnvelope() || $this->envelopeKeys !== null;
    }

    public function key(SortCriterion $criterion): int|string
    {
        return match ($criterion) {
            SortCriterion::Arrival => $this->arrival,
            SortCriterion::Size => $this->size,
            SortCriterion::Date, SortCriterion::From, SortCriterion::Subject, SortCriterion::To, SortCriterion::Cc => $this->envelopeKeys[$criterion->name]
                ?? throw new \LogicException("Sort by {$criterion->name} asked of keys loaded without the envelope"),
        };
    }
}
