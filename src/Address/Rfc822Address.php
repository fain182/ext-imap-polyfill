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

    public const SPECIALS = "()<>@,;:\\\"[].".self::CONTROL_CHARS;

    public static function write(string $mailbox, string $hostname, string $personal): string
    {
        $address = "{$mailbox}@{$hostname}";

        if ($personal === '') {
            return $address;
        }

        return self::quote($personal, self::SPECIALS).' <'.$address.'>';
    }

    public static function quote(string $value, string $specials): string
    {
        if ($value !== '' && strpbrk($value, $specials) === false) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
