<?php

namespace ImapPolyfill\Message;

/**
 * The closed set of orderings imap_sort() sorts by, as the type the polyfill
 * carries past the imap_* boundary.
 *
 * An enum rather than the raw SORT* int, so that every `match` over a
 * criterion is exhaustive by construction and a criterion added here without
 * an arm everywhere is a static-analysis error. As an int the same omission
 * fell into a default arm and sorted plausibly but wrongly, which is a
 * failure nobody sees.
 */
enum SortCriterion
{
    case Date;
    case Arrival;
    case From;
    case Subject;
    case To;
    case Cc;
    case Size;

    /**
     * Reads imap_sort()'s $criteria argument, whose values are c-client's
     * (mail.h SORTDATE…), taken from the constants rather than duplicated
     * here so mail.h stays their single source.
     *
     * null for anything else: this is the boundary, and the one place where
     * an unknown criterion is an answer to give rather than a hole to fall
     * into — imap_sort() rejects it with a ValueError.
     */
    public static function tryFromConstant(int $criteria): ?self
    {
        return match ($criteria) {
            SORTDATE => self::Date,
            SORTARRIVAL => self::Arrival,
            SORTFROM => self::From,
            SORTSUBJECT => self::Subject,
            SORTTO => self::To,
            SORTCC => self::Cc,
            SORTSIZE => self::Size,
            default => null,
        };
    }
}
