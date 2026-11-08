<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Address\Address;
use ImapPolyfill\Address\Rfc822Cursor;

/**
 * Message-IDs as c-client's threading reads them (mail_thread_parse_msgid()
 * and mail_thread_parse_references() in mail.c): not split on whitespace,
 * but parsed as RFC 822 route-addresses, any phrase in front skipped, and
 * answered as "mailbox@host" — so "< a @ x >" and "<\"a\"@x>" both read as
 * "a@x", and "a@x" with no brackets is no id at all. The first id that does
 * not parse ends the list.
 */
final class MessageId
{
    /** c-client's BADHOST, the host an id without one is given. */
    private const MISSING_HOST = '.MISSING-HOST-NAME.';

    public static function first(string $header): ?string
    {
        return self::next(new Rfc822Cursor($header));
    }

    /**
     * @return list<string>
     */
    public static function all(string $header): array
    {
        $cursor = new Rfc822Cursor($header);
        $ids = [];

        while (!$cursor->isCancelled() && ($id = self::next($cursor)) !== null) {
            $ids[] = $id;
        }

        return $ids;
    }

    private static function next(Rfc822Cursor $cursor): ?string
    {
        $cursor->skipWhitespaceAndComments();

        if ($cursor->peek() !== '<' && $cursor->readPhrase() === null) {
            return null;
        }

        $address = Address::parseRouteAddress($cursor, self::MISSING_HOST)[0] ?? null;

        return $address?->mailbox !== null && $address->host !== null
            ? $address->mailbox.'@'.$address->host
            : null;
    }
}
