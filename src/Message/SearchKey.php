<?php

namespace ImapPolyfill\Message;

/**
 * The search criteria imap_search() understands, which is a smaller set than
 * IMAP's own SEARCH grammar: c-client parses the criteria string with
 * mail_criteria() (mail.c) into a SEARCHPGM and rebuilds the command from
 * that, so a keyword the struct has no field for cannot survive the trip.
 *
 * HEADER, OR, NOT, LARGER, SMALLER, DRAFT and the SENT* dates are all absent
 * from it, and a criteria string naming one is refused rather than sent on.
 */
enum SearchKey: string
{
    case All = 'ALL';
    case Answered = 'ANSWERED';
    case Bcc = 'BCC';
    case Before = 'BEFORE';
    case Body = 'BODY';
    case Cc = 'CC';
    case Deleted = 'DELETED';
    case Flagged = 'FLAGGED';
    case From = 'FROM';
    case Keyword = 'KEYWORD';
    case New = 'NEW';
    case Old = 'OLD';
    case On = 'ON';
    case Recent = 'RECENT';
    case Seen = 'SEEN';
    case Since = 'SINCE';
    case Subject = 'SUBJECT';
    case Text = 'TEXT';
    case To = 'TO';
    case Unanswered = 'UNANSWERED';
    case Undeleted = 'UNDELETED';
    case Unflagged = 'UNFLAGGED';
    case Unkeyword = 'UNKEYWORD';
    case Unseen = 'UNSEEN';

    /**
     * The ones mail_criteria() reads a value for — a quoted string, a
     * literal, or a bare atom up to the next space.
     */
    public function takesArgument(): bool
    {
        return match ($this) {
            self::Bcc, self::Body, self::Cc, self::From, self::Keyword,
            self::Subject, self::Text, self::To, self::Unkeyword,
            self::Before, self::On, self::Since => true,

            self::All, self::Answered, self::Deleted, self::Flagged,
            self::New, self::Old, self::Recent, self::Seen,
            self::Unanswered, self::Undeleted, self::Unflagged,
            self::Unseen => false,
        };
    }

    /** Whether the value is a date rather than free text. */
    public function takesDate(): bool
    {
        return match ($this) {
            self::Before, self::On, self::Since => true,
            default => false,
        };
    }
}
