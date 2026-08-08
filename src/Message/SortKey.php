<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Address\AddressList;

/**
 * Derives an imap_sort() comparison key for a single fetched message, one
 * SORT* criterion at a time. Field interpretation (parsing raw headers into
 * comparable values) belongs here rather than in Session\Mailbox, the same
 * way HeaderInfo/Overview/HeadersLine own it for their respective functions.
 */
final class SortKey
{
    /**
     * @param array<string, mixed> $message RFC822.HEADER/INTERNALDATE/RFC822.SIZE keyed wire fetch data
     */
    public static function resolve(SortCriterion $criteria, array $message, string $defaultHost): int|string
    {
        $fields = RawHeaderFields::parse($message['RFC822.HEADER']);

        return match ($criteria) {
            SortCriterion::Date => strtotime($fields['date'] ?? '') ?: 0,
            SortCriterion::Arrival => strtotime($message['INTERNALDATE']) ?: 0,
            SortCriterion::Size => (int) $message['RFC822.SIZE'],
            SortCriterion::From => self::mailboxKey($fields['from'] ?? null, $defaultHost),
            SortCriterion::To => self::mailboxKey($fields['to'] ?? null, $defaultHost),
            SortCriterion::Cc => self::mailboxKey($fields['cc'] ?? null, $defaultHost),
            SortCriterion::Subject => BaseSubject::of($fields['subject'] ?? ''),
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

    private static function mailboxKey(?string $addressHeader, string $defaultHost): string
    {
        if ($addressHeader === null) {
            return '';
        }

        $address = AddressList::parse($addressHeader, $defaultHost)->first();

        return $address === null ? '' : strtolower($address->mailbox);
    }
}
