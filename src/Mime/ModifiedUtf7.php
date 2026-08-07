<?php

namespace ImapPolyfill\Mime;

/**
 * Modified UTF-7 (RFC 3501 §5.1.3) decoding.
 *
 * mb_convert_encoding() answers with mangled text for input that isn't valid
 * modified UTF-7 — an unterminated base64 run, or a trailing "&" — where
 * c-client refuses it. Since these functions report failure as false, the
 * input is validated before conversion.
 */
final class ModifiedUtf7
{
    /**
     * Every "&" opens a run that has to close with "-"; "&-" is a literal
     * ampersand. The run's alphabet is base64 with "," for "/".
     */
    private const VALID = '/^(?:[^&]|&[A-Za-z0-9+,]*-)*$/';

    public static function toUtf8(string $string): string|false
    {
        return self::convert($string, 'UTF-8');
    }

    public static function toIso88591(string $string): string|false
    {
        return self::convert($string, 'ISO-8859-1');
    }

    private static function convert(string $string, string $to): string|false
    {
        if (preg_match(self::VALID, $string) !== 1) {
            return false;
        }

        return @mb_convert_encoding($string, $to, 'UTF7-IMAP');
    }
}
