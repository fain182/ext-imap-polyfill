<?php

namespace ImapPolyfill\Connection\Pop3;

use ImapPolyfill\Message\RawHeaderFields;

/**
 * Client-side evaluator for the SEARCH criteria this polyfill's Mailbox
 * hands over as whitespace-split tokens (see Mailbox::search()) — POP3 has
 * no SEARCH command, so real ext-imap fetches every message and filters
 * locally the same way. Covers the common criteria; anything unrecognized
 * is treated as unmatched rather than throwing, the same permissive
 * fallback c-client's own generic search takes for keywords it doesn't
 * recognize on a given driver.
 */
final class Pop3SearchEvaluator
{
    /**
     * @param string[] $tokens
     * @param string[] $flags
     */
    public static function matches(array $tokens, string $rawMessage, array $flags): bool
    {
        [$header] = self::splitHeaderBody($rawMessage);
        $fields = RawHeaderFields::parse($header);

        $i = 0;
        $count = count($tokens);
        while ($i < $count) {
            $token = strtoupper($tokens[$i]);
            $i++;

            $matched = match ($token) {
                'ALL' => true,
                'ANSWERED' => in_array('\\Answered', $flags, true),
                'UNANSWERED' => !in_array('\\Answered', $flags, true),
                'DELETED' => in_array('\\Deleted', $flags, true),
                'UNDELETED' => !in_array('\\Deleted', $flags, true),
                'FLAGGED' => in_array('\\Flagged', $flags, true),
                'UNFLAGGED' => !in_array('\\Flagged', $flags, true),
                'SEEN' => in_array('\\Seen', $flags, true),
                'UNSEEN' => !in_array('\\Seen', $flags, true),
                'DRAFT' => in_array('\\Draft', $flags, true),
                'UNDRAFT' => !in_array('\\Draft', $flags, true),
                'RECENT', 'NEW' => true, // every POP3 message is "recent" for the session
                'OLD' => false,
                'FROM', 'TO', 'CC', 'BCC', 'SUBJECT' => self::substringMatch($fields, strtolower($token), self::nextToken($tokens, $i)),
                'BODY' => str_contains(strtolower(self::splitHeaderBody($rawMessage)[1]), strtolower(self::nextToken($tokens, $i))),
                'TEXT' => str_contains(strtolower($rawMessage), strtolower(self::nextToken($tokens, $i))),
                'SINCE', 'BEFORE', 'ON' => self::dateMatch($token, $fields['date'] ?? null, self::nextToken($tokens, $i)),
                default => true, // unrecognized keyword: don't exclude the message on it
            };

            if (in_array($token, ['FROM', 'TO', 'CC', 'BCC', 'SUBJECT', 'BODY', 'TEXT', 'SINCE', 'BEFORE', 'ON'], true)) {
                $i++; // consumed one value token
            }

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string[] $tokens
     */
    private static function nextToken(array $tokens, int $i): string
    {
        return $tokens[$i] ?? '';
    }

    /**
     * @param array<string, string> $fields
     */
    private static function substringMatch(array $fields, string $field, string $needle): bool
    {
        return str_contains(strtolower($fields[$field] ?? ''), strtolower($needle));
    }

    private static function dateMatch(string $op, ?string $headerDate, string $needle): bool
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

        return match ($op) {
            'SINCE' => $messageDay >= $criterionDay,
            'BEFORE' => $messageDay < $criterionDay,
            'ON' => $messageDay === $criterionDay,
            default => false,
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitHeaderBody(string $raw): array
    {
        $pos = preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;

        if ($pos === null) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $pos), substr($raw, $pos + strlen($m[0][0]))];
    }
}
