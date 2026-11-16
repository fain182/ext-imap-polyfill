<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Address\AddressList;

/**
 * Turns a fetched message's fields into imap_sort() comparison keys. That
 * belongs here rather than in Session\Mailbox, the same way HeaderInfo,
 * Overview and HeadersLine own it for their respective functions;
 * mail_sort_loadcache() reads the keys out of mail_fetchenvelope(), so they
 * come from the same Envelope imap_headerinfo() reports.
 */
final class SortKey
{
    public static function arrival(string $internalDate): int
    {
        return strtotime($internalDate) ?: 0;
    }

    /**
     * mail_sort_loadcache() sorts a message whose Date: is missing or
     * unparseable by its internal date, not ahead of every other.
     */
    public static function date(Envelope $envelope, int $arrival): int
    {
        return strtotime($envelope->date ?? '') ?: $arrival;
    }

    public static function mailbox(?AddressList $addresses): string
    {
        $address = $addresses?->first();

        return $address === null ? '' : strtolower((string) $address->mailbox);
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
}
