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
    public static function resolve(int $criteria, array $message, string $defaultHost): int|string
    {
        $fields = RawHeaderFields::parse($message['RFC822.HEADER']);

        return match ($criteria) {
            SORTDATE => strtotime($fields['date'] ?? '') ?: 0,
            SORTARRIVAL => strtotime($message['INTERNALDATE']) ?: 0,
            SORTSIZE => (int) $message['RFC822.SIZE'],
            SORTFROM => self::mailboxKey($fields['from'] ?? null, $defaultHost),
            SORTTO => self::mailboxKey($fields['to'] ?? null, $defaultHost),
            SORTCC => self::mailboxKey($fields['cc'] ?? null, $defaultHost),
            SORTSUBJECT => BaseSubject::of($fields['subject'] ?? ''),
            default => 0,
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
