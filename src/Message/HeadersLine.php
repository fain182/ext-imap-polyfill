<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Address\AddressList;

/**
 * Builds one imap_headers() summary line, matching php_imap.c's
 * PHP_FUNCTION(imap_headers): a fixed-column "elm/pine style" overview —
 * flag chars, msgno, date, from, subject, size — mirroring c-client's
 * mail_date()/mail_fetchfrom()/mail_fetchsubject() column widths.
 */
final class HeadersLine
{
    private const FROM_WIDTH = 20;
    private const SUBJECT_WIDTH = 25;

    /**
     * @param string[] $flags
     * @param string[] $registeredUserFlags
     */
    public static function build(
        string $rawHeader,
        array $flags,
        string $internalDate,
        int $size,
        int $msgno,
        string $defaultHost,
        array $registeredUserFlags = [],
    ): string {
        $fields = RawHeaderFields::parse($rawHeader);
        $subject = $fields['subject'] ?? '';

        return self::flagChars($flags)
            .sprintf('%4d)', $msgno)
            .self::dateField($internalDate)
            .' '.str_pad(substr(self::fromField($fields, $defaultHost), 0, self::FROM_WIDTH), self::FROM_WIDTH)
            .' '.self::userFlagsField($flags, $registeredUserFlags)
            .substr($subject, 0, self::SUBJECT_WIDTH)
            .sprintf(' (%d chars)', $size);
    }

    /**
     * The "{Keyword1 Keyword2} " segment between from and subject. Like
     * c-client's user_flags bitmask rendering: only keywords the session
     * has registered appear, in registration order — a keyword the server
     * reports but the session never registered is dropped.
     *
     * @param string[] $flags
     * @param string[] $registeredUserFlags
     */
    private static function userFlagsField(array $flags, array $registeredUserFlags): string
    {
        $custom = array_values(array_intersect($registeredUserFlags, $flags));

        return $custom === [] ? '' : '{'.implode(' ', $custom).'} ';
    }

    /**
     * Re-pads the day like c-client's mail_date(): space-padded, not
     * zero-padded (e.g. " 7-Jul-2026", not "07-Jul-2026").
     */
    private static function dateField(string $internalDate): string
    {
        $day = (int) substr($internalDate, 0, 2);

        return sprintf('%2d', $day).substr($internalDate, 2, 9);
    }

    /**
     * @param array<string, string> $fields
     */
    private static function fromField(array $fields, string $defaultHost): string
    {
        if (!isset($fields['from'])) {
            return '';
        }

        $address = AddressList::parse($fields['from'], $defaultHost)->first();

        if ($address === null) {
            return '';
        }

        return $address->personal ?? "{$address->mailbox}@{$address->host}";
    }

    /**
     * @param string[] $flags
     */
    private static function flagChars(array $flags): string
    {
        $recent = in_array('\\Recent', $flags, true);
        $seen = in_array('\\Seen', $flags, true);

        return ($recent ? ($seen ? 'R' : 'N') : ' ')
            .(($recent || $seen) ? ' ' : 'U')
            .(in_array('\\Flagged', $flags, true) ? 'F' : ' ')
            .(in_array('\\Answered', $flags, true) ? 'A' : ' ')
            .(in_array('\\Deleted', $flags, true) ? 'D' : ' ')
            .(in_array('\\Draft', $flags, true) ? 'X' : ' ');
    }
}
