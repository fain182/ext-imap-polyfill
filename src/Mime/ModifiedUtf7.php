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

    /**
     * imap_utf8_to_mutf7(): c-client's utf8_to_mutf7(), which shifts into a
     * BASE64 run for the octets with the high bit set and for nothing else.
     * Every ASCII character is copied as it stands — control characters
     * included, where mbstring's UTF7-IMAP encoder escapes them — and only
     * "&" is special enough to need an escape of its own.
     *
     * Input that is not UTF-8 is refused rather than substituted: utf8_get()
     * answers an error and c-client returns NIL.
     */
    public static function fromUtf8(string $string): string|false
    {
        if (!mb_check_encoding($string, 'UTF-8')) {
            return false;
        }

        $result = '';
        $offset = 0;
        $length = strlen($string);

        while ($offset < $length) {
            $char = $string[$offset];

            if ($char < "\x80") {
                $result .= $char === '&' ? '&-' : $char;
                ++$offset;

                continue;
            }

            $run = '';

            while ($offset < $length && $string[$offset] >= "\x80") {
                $run .= $string[$offset++];
            }

            $utf16 = (string) mb_convert_encoding($run, 'UTF-16BE', 'UTF-8');
            $result .= '&'.strtr(rtrim(base64_encode($utf16), '='), '/', ',').'-';
        }

        return $result;
    }

    /**
     * imap_mutf7_to_utf8(): the inverse, gated by mail_utf7_valid() — an
     * octet with the high bit set is reserved for a future that never came
     * and refuses the whole string, as does a run that never shifts back.
     * What is not in a run is ASCII text and stays as it is.
     */
    public static function toUtf8(string $string): string|false
    {
        if (preg_match(self::VALID, $string) !== 1 || preg_match('/[\x80-\xFF]/', $string) === 1) {
            return false;
        }

        return (string) preg_replace_callback(
            '/&([A-Za-z0-9+,]*)-/',
            static function (array $match): string {
                if ($match[1] === '') {
                    return '&';
                }

                $base64 = strtr($match[1], ',', '/');
                $utf16 = base64_decode($base64.str_repeat('=', (4 - strlen($base64) % 4) % 4));

                // A run that decodes to half a surrogate pair, or to an odd
                // number of octets, is not text: c-client's converter writes
                // nothing for it, where mbstring would write a substitute
                // character per unit it could not read.
                $substitute = mb_substitute_character();
                mb_substitute_character('none');

                try {
                    return (string) mb_convert_encoding((string) $utf16, 'UTF-8', 'UTF-16BE');
                } finally {
                    mb_substitute_character($substitute);
                }
            },
            $string
        );
    }
}
