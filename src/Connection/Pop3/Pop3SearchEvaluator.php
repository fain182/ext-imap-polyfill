<?php

namespace ImapPolyfill\Connection\Pop3;

use ImapPolyfill\Message\SearchCriterion;
use ImapPolyfill\Message\SearchKey;
use ImapPolyfill\Message\SearchProgram;
use ImapPolyfill\Mime\MimeText;

/**
 * Client-side evaluator for a parsed search program — POP3 has no SEARCH
 * command, so c-client fetches every message and runs the program locally
 * (mail_search_msg in mail.c), and so does this.
 *
 * The vocabulary is closed before it gets here: SearchProgram has already
 * refused anything mail_criteria() would not build, so every key has an arm
 * and there is no default to fall through to.
 */
final class Pop3SearchEvaluator
{
    /**
     * @param string[]           $flags   the IMAP flag names set on this message
     * @param SearchCharset|null $charset what the terms are written in; none
     *                                    named leaves them as they are
     */
    public static function matches(SearchProgram $program, RawMessage $message, array $flags, ?SearchCharset $charset = null): bool
    {
        foreach ($program->criteria as $criterion) {
            if (!self::matchesCriterion($criterion, $message, $flags, $charset)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string[] $flags
     */
    private static function matchesCriterion(SearchCriterion $criterion, RawMessage $message, array $flags, ?SearchCharset $charset): bool
    {
        $fields = $message->getHeaders();
        $argument = $criterion->argument ?? '';
        // utf8_searchpgm() converts the address, subject, body and text
        // terms. A keyword is a flag name and a date is a date, so those
        // are left as written.
        $term = $charset?->toUtf8($argument) ?? $argument;

        return match ($criterion->key) {
            SearchKey::All => true,

            SearchKey::Answered => self::hasFlag($flags, '\\Answered'),
            SearchKey::Unanswered => !self::hasFlag($flags, '\\Answered'),
            SearchKey::Deleted => self::hasFlag($flags, '\\Deleted'),
            SearchKey::Undeleted => !self::hasFlag($flags, '\\Deleted'),
            SearchKey::Flagged => self::hasFlag($flags, '\\Flagged'),
            SearchKey::Unflagged => !self::hasFlag($flags, '\\Flagged'),
            SearchKey::Seen => self::hasFlag($flags, '\\Seen'),
            SearchKey::Unseen => !self::hasFlag($flags, '\\Seen'),

            SearchKey::Keyword => self::hasFlag($flags, $argument),
            SearchKey::Unkeyword => !self::hasFlag($flags, $argument),

            // Every message a POP3 session finds is recent to it: the
            // protocol keeps no record of a previous one having seen it.
            // NEW is recent *and* unseen, which leaves the second half.
            SearchKey::Recent => true,
            SearchKey::New => !self::hasFlag($flags, '\\Seen'),
            SearchKey::Old => false,

            SearchKey::Bcc, SearchKey::Cc, SearchKey::From,
            SearchKey::Subject, SearchKey::To => self::substringMatch($fields, strtolower($criterion->key->value), $term),
            SearchKey::Body => self::contains($message->getBody(), $term),
            // mail_search_text() reads the header through utf8_mime2text()
            // before it goes on to the body.
            SearchKey::Text => self::contains(MimeText::decode($message->getRawHeader()), $term)
                || self::contains($message->getBody(), $term),

            SearchKey::Before, SearchKey::On,
            SearchKey::Since => self::dateMatch($criterion->key, $fields['date'] ?? null, $argument),
        };
    }

    /**
     * @param string[] $flags
     */
    private static function hasFlag(array $flags, string $flag): bool
    {
        if ($flag === '') {
            return false;
        }

        foreach ($flags as $set) {
            if (strcasecmp($set, $flag) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function substringMatch(array $fields, string $field, string $needle): bool
    {
        // mail_search_addr() and mail_search_header_text() answer NIL for a
        // header the message does not have, whatever the needle, and both
        // end in mail_search_header(), which compares against the header
        // as utf8_mime2text() decodes it.
        return isset($fields[$field]) && self::contains(MimeText::decode($fields[$field]), $needle);
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return str_contains(strtolower($haystack), strtolower($needle));
    }

    private static function dateMatch(SearchKey $key, ?string $headerDate, string $needle): bool
    {
        if ($headerDate === null || $needle === '') {
            return false;
        }

        $messageTime = strtotime($headerDate);
        $criterionTime = strtotime($needle);

        if ($messageTime === false || $criterionTime === false) {
            return false;
        }

        $messageDay = strtotime(date('Y-m-d', $messageTime));
        $criterionDay = strtotime(date('Y-m-d', $criterionTime));

        return match ($key) {
            SearchKey::Since => $messageDay >= $criterionDay,
            SearchKey::Before => $messageDay < $criterionDay,
            SearchKey::On => $messageDay === $criterionDay,
            default => throw new \LogicException('Not a date criterion: '.$key->value),
        };
    }
}
