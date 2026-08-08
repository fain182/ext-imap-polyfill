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

    /**
     * The encoding side of imap_utf7_encode(), which is not the inverse of
     * the decoding above and is not modified UTF-7 as RFC 3501 defines it.
     *
     * The base64 run holds the input's own bytes rather than the UTF-16
     * units they stand for: "caff\xC3\xA8" becomes "caff&w6g-", the base64
     * of C3 A8, and not "caff&AMMAqA-", the base64 of 00C3 00A8. Every
     * other detail is modified UTF-7's — "/" written as ",", padding
     * dropped, "&" written as "&-", and the run closed with "-".
     */
    public static function fromBytes(string $string): string
    {
        return (string) preg_replace_callback(
            '/[^\x20-\x7E]+/',
            static fn (array $m): string => '&'.strtr(rtrim(base64_encode($m[0]), '='), '/', ',').'-',
            str_replace('&', '&-', $string)
        );
    }

    /**
     * The inverse of fromBytes(), and what imap_utf7_decode() answers: the
     * bytes the base64 runs stand for, handed back as they were. Like its
     * counterpart it converts nothing — feed it what imap_utf7_encode()
     * produced and the original string comes back byte for byte, whatever
     * charset those bytes were in.
     */
    public static function toBytes(string $string): string|false
    {
        if (preg_match(self::VALID, $string) !== 1) {
            return false;
        }

        return (string) preg_replace_callback(
            '/&([^-]*)-/',
            static fn (array $m): string => $m[1] === ''
                ? '&'
                : (string) base64_decode(strtr($m[1], ',', '/'), true),
            $string
        );
    }

    public static function toUtf8(string $string): string|false
    {
        return self::convert($string, 'UTF-8');
    }

    private static function convert(string $string, string $to): string|false
    {
        if (preg_match(self::VALID, $string) !== 1) {
            return false;
        }

        return @mb_convert_encoding($string, $to, 'UTF7-IMAP');
    }
}
