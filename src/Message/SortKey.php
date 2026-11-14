<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Address\AddressList;

/**
 * Derives an imap_sort() comparison key for a single fetched message, one
 * SORT* criterion at a time. Turning envelope fields into comparable values
 * belongs here rather than in Session\Mailbox, the same way HeaderInfo,
 * Overview and HeadersLine own it for their respective functions.
 */
final class SortKey
{
    /**
     * mail_sort_loadcache() reads the keys out of mail_fetchenvelope(), so
     * they come from the same Envelope imap_headerinfo() reports.
     */
    public static function resolve(SortCriterion $criteria, Envelope $envelope, string $internalDate, int $size): int|string
    {
        $arrival = strtotime($internalDate) ?: 0;

        return match ($criteria) {
            // mail_sort_loadcache() sorts a message whose Date: is missing or
            // unparseable by its internal date, not ahead of every other.
            SortCriterion::Date => strtotime($envelope->date ?? '') ?: $arrival,
            SortCriterion::Arrival => $arrival,
            SortCriterion::Size => $size,
            SortCriterion::From => self::mailboxKey($envelope->from()),
            SortCriterion::To => self::mailboxKey($envelope->to()),
            SortCriterion::Cc => self::mailboxKey($envelope->cc()),
            SortCriterion::Subject => BaseSubject::of($envelope->subject ?? ''),
        };
    }

    /**
     * The RFC 5256 sort-key name a criterion becomes on the wire, for servers
     * that advertise the SORT capability.
     */
    public static function wireName(SortCriterion $criteria): string
    {
        return match ($criteria) {
            SortCriterion::Date => 'DATE',
            SortCriterion::Arrival => 'ARRIVAL',
            SortCriterion::Size => 'SIZE',
            SortCriterion::From => 'FROM',
            SortCriterion::To => 'TO',
            SortCriterion::Cc => 'CC',
            SortCriterion::Subject => 'SUBJECT',
        };
    }

    private static function mailboxKey(?AddressList $addresses): string
    {
        $address = $addresses?->first();

        return $address === null ? '' : strtolower((string) $address->mailbox);
    }
}
