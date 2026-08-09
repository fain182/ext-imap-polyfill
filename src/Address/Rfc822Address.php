<?php

namespace ImapPolyfill\Address;

/**
 * Writing an address back out, c-client's rfc822_write_address(): the
 * personal part is quoted when it holds anything RFC 822 calls special, and
 * only backslash and double-quote are escaped inside the quotes.
 *
 * Shared with Mime\ComposedMessage on purpose. The two had drifted — the
 * standalone function only ever quoted on a comma, so a personal name
 * containing a double-quote came out unquoted and produced a header the
 * real extension would have quoted.
 */
final class Rfc822Address
{
    private const CONTROL_CHARS = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /** c-client's rspecials, which is what a personal name is quoted against. */
    public const SPECIALS = "()<>@,;:\\\"[].".self::CONTROL_CHARS;

    /** Its wspecials: the same list with a space instead of the dot. */
    private const WORD_SPECIALS = " ()<>@,;:\\\"[]".self::CONTROL_CHARS;

    public static function write(string $mailbox, string $hostname, string $personal): string
    {
        $address = self::quoteWord($mailbox);

        // "A null host (HIGHLY discouraged!)": c-client writes the mailbox
        // and stops, rather than the "@" of an address that has no domain.
        if (!str_starts_with($hostname, '@')) {
            $address .= '@'.self::quoteWord($hostname);
        }

        if ($personal === '') {
            return $address;
        }

        return self::quote($personal, self::SPECIALS).' <'.$address.'>';
    }

    /**
     * A mailbox or a host as rfc822_output_cat() writes one, which is not the
     * rule the personal name goes by: a dot is ordinary in the middle and
     * forces quoting at either end or doubled, and the empty string is
     * written as a pair of quotes rather than as nothing at all.
     */
    private static function quoteWord(string $value): string
    {
        $needsQuoting = $value === ''
            || strpbrk($value, self::WORD_SPECIALS) !== false
            || str_starts_with($value, '.')
            || str_ends_with($value, '.')
            || str_contains($value, '..');

        return $needsQuoting
            ? '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"'
            : $value;
    }

    public static function quote(string $value, string $specials): string
    {
        if ($value !== '' && strpbrk($value, $specials) === false) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
