<?php

namespace ImapPolyfill\Message;

/**
 * imap_search()'s criteria string, parsed the way c-client's mail_criteria()
 * (mail.c) parses it, and into what it builds: keywords from a closed set,
 * each with the value it takes, if it takes one. The string itself does not
 * travel on — the IMAP backend writes a SEARCH command from the criteria, as
 * c-client writes one from its search program.
 *
 * An unrecognized keyword is not passed on for a server to judge — it fails
 * the whole search, which is why this parses at the boundary rather than
 * letting each backend make its own sense of the string.
 *
 * The parse is strtok_r() over a buffer it writes into, and the port keeps
 * both. Keywords are separated by spaces and nothing else, so a tab is part
 * of the keyword next to it. A value is read from right after its keyword's
 * single space (mail_criteria_string()): a "{n}" literal, a quoted string
 * running to the next quote, or an atom to the next space. A value that
 * starts with a second space, an empty or unclosed quote, or a literal
 * whose length does not fit is no value, and the keyword is what fails.
 */
final class SearchProgram
{
    /**
     * @param list<SearchCriterion> $criteria
     */
    private function __construct(
        public readonly array $criteria,
    ) {
    }

    /**
     * @throws \RuntimeException carrying c-client's own message, which the
     *                           imap_* layer turns into false plus a stack entry
     */
    public static function parse(string $criteria): self
    {
        // c-client reads the argument as a C string.
        $nul = strpos($criteria, "\0");
        $buffer = ($nul === false ? $criteria : substr($criteria, 0, $nul))."\0";
        $rest = 0;
        $parsed = [];

        for ($at = self::strtok($buffer, 0, $rest, ' '); $at !== null; $at = self::strtok($buffer, $rest, $rest, ' ')) {
            $word = strtr(self::cString($buffer, $at), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
            $key = SearchKey::tryFrom($word);

            if ($key === null) {
                throw self::unknown($word);
            }

            if (!$key->takesArgument()) {
                $parsed[] = new SearchCriterion($key);

                continue;
            }

            $argument = self::value($buffer, $rest);

            if ($argument !== null && $key->takesDate()) {
                // mail_criteria_date(): mail_parse_date(), packed into a
                // shortdate, which has to come out non-zero.
                $date = MailDate::parse($argument);

                // A month of 0 ("20260006") is one difference left: c-client
                // keeps it, prints it from before the start of its month
                // table, and searches locally once the server refuses that.
                // With no such fallback here there is no command to send, so
                // it fails as a date c-client could not read.
                $argument = $date !== null && $date->packed() !== 0 && $date->month !== 0 ? $date->toImap() : null;
            }

            if ($argument === null) {
                throw self::unknown($word);
            }

            $parsed[] = new SearchCriterion($key, $argument);
        }

        return new self($parsed);
    }

    /**
     * mail_criteria_string(): the value from where the keyword's strtok
     * stopped, read by strtok with no delimiters at all — the whole rest.
     */
    private static function value(string &$buffer, int &$rest): ?string
    {
        $at = self::strtok($buffer, $rest, $rest, '');

        if ($at === null) {
            return null;
        }

        $delimiter = ' ';

        switch ($buffer[$at]) {
            case '{':
                return self::literal($buffer, $at, $rest);

            case ' ':
                return null;

            case '"':
                $close = strpos($buffer, '"', $at + 1);

                if ($close === false || $close > strcspn($buffer, "\0", $at) + $at) {
                    return null;
                }

                $delimiter = '"';
        }

        $token = self::strtok($buffer, $at, $rest, $delimiter);

        return $token === null ? null : self::cString($buffer, $token);
    }

    /**
     * "{n}" CRLF and n bytes, ending the string or followed by a space.
     * strtoul() reads n, so leading whitespace and a "+" are allowed and no
     * digits at all is a length of 0.
     */
    private static function literal(string &$buffer, int $at, int &$rest): ?string
    {
        $digitsAt = $at + 1 + strspn($buffer, " \t\n\v\f\r", $at + 1);

        if ($buffer[$digitsAt] === '+') {
            $digitsAt++;
        }

        $digits = strspn($buffer, '0123456789', $digitsAt);
        $length = $digits === 0 ? 0 : (int) substr($buffer, $digitsAt, min($digits, 18));
        $close = $digits === 0 ? $at + 1 : $digitsAt + $digits;
        $data = $close + 3;
        $end = $data + $length;

        if (substr($buffer, $close, 3) !== "}\r\n" || $digits > 18 || $end >= strlen($buffer)
            || ($buffer[$end] !== "\0" && $buffer[$end] !== ' ')) {
            return null;
        }

        // c-client restarts strtok on the literal's last byte, made a
        // non-space for the purpose, so the scan stops right behind it.
        if ($buffer[$end] === ' ') {
            $buffer[$end] = "\0";
            $rest = $end + 1;
        } else {
            $rest = $end;
        }

        return self::cString($buffer, $data);
    }

    /**
     * strtok_r() from $from: skip the delimiters, then cut the token at the
     * next one by writing a NUL over it. Answers where the token starts.
     */
    private static function strtok(string &$buffer, int $from, int &$rest, string $delimiters): ?int
    {
        $start = $from + strspn($buffer, $delimiters, $from);

        if ($buffer[$start] === "\0") {
            $rest = $start;

            return null;
        }

        $end = $start + strcspn($buffer, $delimiters."\0", $start);

        if ($buffer[$end] === "\0") {
            $rest = $end;
        } else {
            $buffer[$end] = "\0";
            $rest = $end + 1;
        }

        return $start;
    }

    private static function cString(string $buffer, int $at): string
    {
        return substr($buffer, $at, strcspn($buffer, "\0", $at));
    }

    private static function unknown(string $criterion): \RuntimeException
    {
        // "Unknown search criterion: %.30s", of the criterion c-client has
        // uppercased in place.
        return new \RuntimeException('Unknown search criterion: '.substr($criterion, 0, 30));
    }
}
