<?php

namespace ImapPolyfill\Message;

/**
 * What ThreadBuilder reads of one message: its numbers, its own id, the ids
 * it refers to, its subject and its date.
 */
final class ThreadMessage
{
    /**
     * @param list<string> $refs
     */
    private function __construct(
        public readonly int $msgno,
        public readonly int $uid,
        public readonly ?string $messageId,
        public readonly array $refs,
        public readonly string $subject,
        public readonly int $date,
    ) {
    }

    /**
     * Where the references come from depends on the driver, as it does in
     * c-client. One with an overview (IMAP) fills the sort cache from it in
     * mail_thread_loadcache(), which leaves an empty list where there is no
     * References header — so In-Reply-To is never read. One without (POP3)
     * reads the envelope, falling back to the first In-Reply-To id when
     * References yields none.
     */
    public static function of(int $msgno, MessageSummary $summary, bool $fromOverview): self
    {
        $envelope = $summary->envelope;
        $refs = MessageId::all($envelope->references ?? '');

        if ($refs === [] && !$fromOverview) {
            $first = MessageId::first($envelope->inReplyTo ?? '');
            $refs = $first === null ? [] : [$first];
        }

        return new self(
            $msgno,
            $summary->uid,
            MessageId::first($envelope->messageId ?? ''),
            $refs,
            $envelope->subject ?? '',
            strtotime($envelope->date ?? '') ?: (strtotime($summary->internalDate) ?: 0),
        );
    }
}
