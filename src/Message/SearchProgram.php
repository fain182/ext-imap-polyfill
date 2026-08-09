<?php

namespace ImapPolyfill\Message;

/**
 * imap_search()'s criteria string, parsed the way c-client's mail_criteria()
 * (mail.c) parses it: a space-separated run of keywords, some of them taking
 * a value, and nothing outside the closed set SearchKey lists.
 *
 * An unrecognized keyword is not passed on for a server to judge — it fails
 * the whole search, which is why this parses at the boundary rather than
 * letting each backend make its own sense of the string.
 */
final class SearchProgram
{
    /**
     * @param list<SearchCriterion> $criteria
     * @param list<string>          $tokens   the string as split for the wire
     */
    private function __construct(
        public readonly array $criteria,
        public readonly array $tokens,
    ) {
    }

    /**
     * @throws \RuntimeException carrying c-client's own message, which the
     *                           imap_* layer turns into false plus a stack entry
     */
    public static function parse(string $criteria): self
    {
        $tokens = $criteria === '' ? [] : (preg_split('/\s+/', trim($criteria)) ?: []);
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));

        $parsed = [];
        $at = 0;
        $count = count($tokens);

        while ($at < $count) {
            $word = $tokens[$at++];
            $key = SearchKey::tryFrom(strtoupper($word));

            if ($key === null) {
                throw self::unknown($word);
            }

            if (!$key->takesArgument()) {
                $parsed[] = new SearchCriterion($key);

                continue;
            }

            // A missing value is not a keyword c-client understands either:
            // mail_criteria_string() answers NIL and the criterion is
            // reported as the unknown one.
            if ($at >= $count) {
                throw self::unknown($word);
            }

            $argument = $tokens[$at++];

            // A quoted value may carry the spaces the split just broke it on.
            if (str_starts_with($argument, '"') && !str_ends_with($argument, '"')) {
                while ($at < $count) {
                    $argument .= ' '.$tokens[$at++];

                    if (str_ends_with($argument, '"')) {
                        break;
                    }
                }
            }

            $argument = trim($argument, '"');

            // A date c-client cannot parse fails as the criterion itself:
            // mail_criteria_date() answers NIL and the keyword is the one
            // reported. It takes a single space-delimited token, so
            // "BEFORE 5 Jan 2026" fails on "5" rather than on "Jan".
            if ($key->takesDate() && !self::isDate($argument)) {
                throw self::unknown($word);
            }

            $parsed[] = new SearchCriterion($key, $argument);
        }

        return new self($parsed, $tokens);
    }

    /**
     * The dates c-client's search program can hold. Its shortdate packs the
     * year into 7 bits above 1970 (mail_shortdate), so 2097 is the last one
     * that fits and 1970 the first — measured against the extension, which
     * refuses 1-Jan-1969 and 1-Jan-2098 alike.
     */
    private static function isDate(string $argument): bool
    {
        $parts = date_parse($argument);

        if ($parts['error_count'] > 0 || $parts['warning_count'] > 0) {
            return false;
        }

        if (!is_int($parts['year']) || !is_int($parts['month']) || !is_int($parts['day'])) {
            return false;
        }

        if (!checkdate($parts['month'], $parts['day'], $parts['year'])) {
            return false;
        }

        return $parts['year'] >= 1970 && $parts['year'] <= 2097;
    }

    private static function unknown(string $criterion): \RuntimeException
    {
        // "Unknown search criterion: %.30s", uppercased as c-client
        // uppercases the criterion in place before comparing it.
        return new \RuntimeException('Unknown search criterion: '.substr(strtoupper($criterion), 0, 30));
    }
}
