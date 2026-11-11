<?php

namespace ImapPolyfill\Message;

/**
 * A calendar date as c-client's mail_parse_date() (mail.c) reads one: the
 * parser behind imap_search()'s BEFORE, ON and SINCE.
 *
 * It is a scanner over a handful of layouts, not a validator. "dd-Mon-yy",
 * "dd Mon yyyy", "mm/dd/yy", ISO "yyyy-mm-dd" and the compact "yyyymmdd",
 * with an optional day of week in front and a time behind that has to be
 * well formed when it is there. Only "day > 31" and "month > 12" are
 * checked, so 31-Feb is a date, and two-digit years follow RFC 2822:
 * 00-48 are 20xx, 49-998 are 19xx and later. The compact form can yield a
 * month or a day of 0, which c-client keeps.
 */
final class MailDate
{
    public const BASE_YEAR = 1970;

    /** BASEYEAR plus the largest value MESSAGECACHE's 7-bit year holds. */
    private const MAX_YEAR = self::BASE_YEAR + 127;

    /** MAILTMPLEN: a date this long is refused before it is read. */
    private const MAX_LENGTH = 1024;

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly int $day,
    ) {
    }

    public static function parse(string $text): ?self
    {
        [$date, $complete] = self::scan($text);

        return $complete ? $date : null;
    }

    /**
     * What mail_parse_date() leaves in the MESSAGECACHE it was handed,
     * whatever it answers: it writes the date before it reads the time, so
     * a date followed by a malformed time is refused and still there.
     * pop3_fetchfast() looks at the cache rather than the answer.
     */
    public static function writtenBy(string $text): ?self
    {
        return self::scan($text)[0];
    }

    /**
     * @return array{?self, bool} the date, and whether the time after it read
     */
    private static function scan(string $text): array
    {
        $nul = strpos($text, "\0");
        $text = $nul === false ? $text : substr($text, 0, $nul);

        if ($text === '' || strlen($text) >= self::MAX_LENGTH) {
            return [null, false];
        }

        $s = strtr($text, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ')."\0";
        $i = 0;

        if (ctype_alpha($s[0]) && ctype_alpha($s[1] ?? '') && ctype_alpha($s[2] ?? '')
            && ($s[3] ?? '') === ',' && ($s[4] ?? '') === ' ') {
            $i = 5;
        }

        while ($s[$i] === ' ') {
            $i++;
        }

        if (($m = self::strtoul($s, $i)) === 0) {
            return [null, false];
        }

        $d = $y = 0;

        switch ($s[$i]) {
            case '/':
                if (ctype_digit($s[++$i]) && ($d = self::strtoul($s, $i)) !== 0 && $s[$i] === '/' && ctype_digit($s[++$i])) {
                    $y = self::strtoul($s, $i);

                    if ($s[$i] === "\0") {
                        break;
                    }
                }

                return [null, false];

            case ' ':
                while ($s[$i + 1] === ' ') {
                    $i++;
                }
                // no break: "dd mmm yy" continues as "dd-mmm-yy"
            case '-':
                if (ctype_digit($s[$i + 1])) {
                    $y = $m;
                    $i++;

                    if (($m = self::strtoul($s, $i)) !== 0 && $s[$i++] === '-'
                        && ($d = self::strtoul($s, $i)) !== 0 && $s[$i] === "\0") {
                        break;
                    }

                    return [null, false];
                }

                $d = $m;

                if (strcspn($s, "\0", $i) < 5) {
                    return [null, false];
                }

                $m = self::month($s[$i + 1], $s[$i + 2], $s[$i + 3]);

                if ($m === null) {
                    return [null, false];
                }

                if ($s[$i + 4] === $s[$i]) {
                    $i += 5;
                } else {
                    $delimiter = $s[$i];

                    for ($i += 4; ctype_alpha($s[$i]); $i++);

                    if ($delimiter !== $s[$i++]) {
                        return [null, false];
                    }
                }

                while ($s[$i] === ' ') {
                    $i++;
                }

                if (ctype_digit($s[$i])) {
                    $y = self::strtoul($s, $i);

                    if ($s[$i] === "\0" || $s[$i] === ' ') {
                        break;
                    }
                }
                // no break: what is left is read as the compact form, which
                // a month number is always too small to be
            case "\0":
                if ($m < self::BASE_YEAR * 10000) {
                    return [null, false];
                }

                $y = intdiv($m, 10000);
                $d = ($m % 10000) % 100;
                $m = intdiv($m % 10000, 100);
                break;

            default:
                return [null, false];
        }

        if ($d > 31 || $m > 12) {
            return [null, false];
        }

        if ($y < 49) {
            $y += 2000;
        } elseif ($y < 999) {
            $y += 1900;
        }

        if ($y < self::BASE_YEAR || $y > self::MAX_YEAR) {
            return [null, false];
        }

        $date = new self($y, $m, $d);

        // A time, when there is one, has to read as one; whatever zone
        // follows it is accepted, an unknown one being taken as local.
        if ($s[$i] !== "\0") {
            $i++;
            $hours = self::strtoul($s, $i);

            if ($s[$i] !== ':') {
                return [$date, false];
            }

            $i++;
            $minutes = self::strtoul($s, $i);
            $seconds = 0;

            if ($s[$i] === ':') {
                $i++;
                $seconds = self::strtoul($s, $i);
            }

            if ($hours > 23 || $minutes > 59 || $seconds > 60) {
                return [$date, false];
            }
        }

        return [$date, true];
    }

    /**
     * mail_shortdate(): the date packed into the 16 bits a search program
     * holds it in. mail_criteria_date() takes 0 for a failed parse, so the
     * one date that packs to 0 — 1970, month 0, day 0 — is refused there.
     */
    public function packed(): int
    {
        return (($this->year - self::BASE_YEAR) << 9) + ($this->month << 5) + $this->day;
    }

    /**
     * How imap_send_sdate() writes the date into a SEARCH command.
     *
     * @throws \LogicException for a month of 0, which c-client writes by
     *                         reading before the start of its month table
     *                         and SearchProgram therefore never asks for
     */
    public function toImap(): string
    {
        if ($this->month === 0) {
            throw new \LogicException('No IMAP date for month 0');
        }

        return $this->day.'-'.self::MONTHS[$this->month - 1].'-'.$this->year;
    }

    /**
     * strtoul(): optional whitespace and sign, then digits. Where there are
     * no digits nothing is consumed — not even the whitespace — and the
     * answer is 0. A negative number wraps to a huge one, which no caller
     * here can accept, so it is answered as the largest int.
     */
    private static function strtoul(string $s, int &$i): int
    {
        $at = $i + strspn($s, " \t\n\v\f\r", $i);
        $negative = $s[$at] === '-';

        if ($s[$at] === '+' || $negative) {
            $at++;
        }

        $digits = strspn($s, '0123456789', $at);

        if ($digits === 0) {
            return 0;
        }

        $i = $at + $digits;
        $value = ltrim(substr($s, $at, $digits), '0');

        if ($negative && $value !== '') {
            return PHP_INT_MAX;
        }

        return strlen($value) > 18 ? PHP_INT_MAX : (int) $value;
    }

    /**
     * The month a three-letter abbreviation names, compared the way
     * c-client compares it: folded into one number by arithmetic on the
     * uppercased bytes, whatever they are.
     */
    private static function month(string $a, string $b, string $c): ?int
    {
        $code = (ord($a) - 65) * 1024 + (ord($b) - 65) * 32 + (ord($c) - 65);

        foreach (self::MONTHS as $index => $name) {
            $upper = strtoupper($name);

            if ($code === (ord($upper[0]) - 65) * 1024 + (ord($upper[1]) - 65) * 32 + (ord($upper[2]) - 65)) {
                return $index + 1;
            }
        }

        return null;
    }
}
