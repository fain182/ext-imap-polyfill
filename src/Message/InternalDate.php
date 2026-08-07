<?php

namespace ImapPolyfill\Message;

/**
 * The INTERNALDATE a server reports, in the shape c-client hands it on.
 */
final class InternalDate
{
    /**
     * Re-pads the day the way c-client's mail_date() does: with a space,
     * not a zero (" 3-Jan-2012", not "03-Jan-2012"). RFC 3501's
     * date-day-fixed lets a server send either, and both fixtures send the
     * zero — so the normalization is what makes the two agree.
     */
    public static function padDay(string $internalDate): string
    {
        return sprintf('%2d', (int) substr($internalDate, 0, 2)).substr($internalDate, 2);
    }
}
