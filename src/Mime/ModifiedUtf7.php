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
        $refusal = self::refusalOf($string);

        if ($refusal !== null) {
            trigger_error("imap_utf7_decode(): {$refusal}", E_USER_WARNING);

            return false;
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+,';
        $result = '';
        $length = strlen($string);

        for ($i = 0; $i < $length; $i++) {
            if ($string[$i] !== '&') {
                $result .= $string[$i];

                continue;
            }

            if ($string[$i + 1] === '-') {
                $result .= '&';
                $i++;

                continue;
            }

            // Six bits per character, a byte out whenever eight have
            // gathered; what is left over when the run closes is dropped,
            // as the bits php_imap.c shifts into a byte it never counts.
            $bits = 0;
            $held = 0;

            for ($i++; $string[$i] !== '-'; $i++) {
                $bits = (($bits << 6) | strpos($alphabet, $string[$i])) & 0xFFFF;
                $held += 6;

                if ($held >= 8) {
                    $held -= 8;
                    $result .= chr(($bits >> $held) & 0xFF);
                }
            }
        }

        return $result;
    }

    /**
     * The first thing php_imap.c's validating pass over imap_utf7_decode()'s
     * input objects to, in its own words, or null when it objects to nothing.
     *
     * This is php_imap.c's own reader, not c-client's mail_utf7_valid(), and
     * it is stricter outside a run than within: a control character or an
     * octet with the high bit set is refused wherever it stands. A run has
     * to close with "-", and closing it one character into a quantum leaves
     * a character that stands for no whole byte, which is refused too.
     */
    private static function refusalOf(string $string): ?string
    {
        $length = strlen($string);
        $inRun = false;
        $quantum = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $string[$i];

            if (!$inRun) {
                if ($char <= "\x1F" || $char >= "\x7F") {
                    return "Invalid modified UTF-7 character: `{$char}'";
                }

                if ($char !== '&') {
                    continue;
                }

                if ($i + 1 === $length) {
                    return 'Unexpected end of string';
                }

                if ($string[$i + 1] === '-') {
                    $i++;
                } else {
                    $inRun = true;
                    $quantum = 0;
                }
            } elseif ($char === '-') {
                if ($quantum === 1) {
                    return "Stray modified base64 character: `{$string[$i - 1]}'";
                }

                $inRun = false;
            } elseif (!ctype_alnum($char) && $char !== '+' && $char !== ',') {
                return "Invalid modified base64 character: `{$char}'";
            } else {
                $quantum = ($quantum + 1) % 4;
            }
        }

        return $inRun ? 'Unexpected end of string' : null;
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
