<?php

namespace ImapPolyfill\Mime;

use ImapPolyfill\Support\ErrorStack;

final class MimeText
{
    private const BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /** The bytes rfc822_base64()'s table marks WSP — note that \v is not one. */
    private const BASE64_WHITESPACE = "\0\t\n\f\r ";

    /**
     * One character of a mime2_token(): printable, and none of the characters
     * RFC 822 gives a meaning to — which is why a charset written "UTF-=8"
     * makes the whole word ordinary text.
     */
    private const MIME2_TOKEN = '[^\x00-\x20\x7F-\xFF()<>@,;:\\\\"\/\[\].=?]';

    /**
     * quoted-printable, decoded the way c-client's rfc822_qprint() does:
     * a "=" that starts neither a hex pair nor a line break is reported,
     * and the text is handed back all the same, quoted from that "="
     * onwards.
     */
    public static function fromQuotedPrintable(string $string): string
    {
        $result = '';
        $length = strlen($string);
        $lastNonSpace = 0;
        $reported = false;

        for ($index = 0; $index < $length;) {
            $char = $string[$index++];

            if ($char === '=') {
                // A "=" with nothing behind it is the soft line break of a
                // string that was cut, and disappears with what it quoted.
                if ($index >= $length) {
                    break;
                }

                $quoted = $string[$index++];

                if ($quoted === "\r" || $quoted === "\n") {
                    if ($quoted === "\r" && ($string[$index] ?? '') === "\n") {
                        $index++;
                    }

                    // A soft break takes the spaces before it with it, which
                    // is what makes the ones after it worth keeping.
                    $lastNonSpace = strlen($result);

                    continue;
                }

                // The second digit is eaten whether or not it turns out to be
                // one — but only once the first has proved itself, which is
                // why "=Doe" reports from the "o" and "=ZZb" from the first
                // "Z".
                if (ctype_xdigit($quoted) && $index < $length && ctype_xdigit($second = $string[$index++])) {
                    $result .= chr((int) hexdec($quoted.$second));
                } else {
                    if (!$reported) {
                        // Once per string, however many faults it holds.
                        $reported = true;
                        ErrorStack::push('Invalid quoted-printable sequence: ='.substr($string, $index - 1, 80));
                    }

                    // Neither refused nor decoded: the "=" and what followed
                    // it stand as the text they evidently are, and the decode
                    // carries on from there.
                    $result .= '='.$quoted;
                }

                $lastNonSpace = strlen($result);

                continue;
            }

            if ($char === ' ') {
                // Stashed, but it does not count as text: a space before the
                // end of a line was put there by a mail system, not by anyone.
                $result .= $char;

                continue;
            }

            if ($char === "\r" || $char === "\n") {
                $result = substr($result, 0, $lastNonSpace);
            }

            $result .= $char;
            $lastNonSpace = strlen($result);
        }

        return $result;
    }

    /**
     * Decodes RFC 2047 encoded-words in a header value to UTF-8.
     *
     * Deliberately hand-rolled instead of using mb_decode_mimeheader(): that
     * function has been observed to return NFD (decomposed) bytes on some
     * platforms even for an already-UTF-8 payload that never needed any
     * charset conversion, silently altering byte-for-byte equality. Skipping
     * any conversion engine when the source charset is already UTF-8/ASCII
     * avoids that class of platform-dependent surprise entirely.
     */
    public static function decode(string $text): string
    {
        $failed = false;

        $decoded = preg_replace_callback(
            // What utf8_mime2text() will look at, and it is narrower than
            // RFC 2047 on both sides. The word has to *be* a whitespace
            // delimited token — one that merely starts inside another is
            // ordinary text, and so is one with anything but whitespace
            // behind its "?=" — which is mime2_text()'s closing test and the
            // "skip to the next space" loop that follows a non-word.
            // Charset and encoding are mime2_token()s: printable, and none of
            // the characters RFC 822 gives a meaning to, "=" included. The
            // encoding is one character wide ("ee == e + 1").
            '/(?:^|(?<=\s))=\?(?P<charset>'.self::MIME2_TOKEN.'*)'
                .'\?(?P<encoding>'.self::MIME2_TOKEN.')'
                .'\?(?P<data>[^\x00-\x20\x7F-\xFF?]*[^?]?)\?=(?=\s|$)'
                .'(?:\s+(?==\?))?/',
            static function (array $matches) use (&$failed, $text): string {
                if ($failed) {
                    return '';
                }

                // Too short to be a word, or too long to be read as one:
                // MINENCWORD and MAXENCWORD, measured as c-client measures
                // them — from the word's start to the end of the text, and
                // from its start to the "?" that closes it.
                $remaining = strlen($text) - $matches[0][1];
                $wordLength = strlen(rtrim($matches[0][0])) - 2;

                if ($remaining <= 9 || $wordLength >= 75) {
                    return $matches[0][0];
                }

                // RFC 2231 lets a charset carry the language it was written
                // in — "UTF-8*en". c-client ties the name off at the "*"
                // before it looks for a converter, and so must this, or the
                // converter is asked for a charset nobody has.
                $charset = strstr($matches['charset'][0], '*', true) ?: $matches['charset'][0];
                $encoding = $matches['encoding'][0];

                if (strcasecmp($encoding, 'B') === 0) {
                    // The warning rfc822_base64() may raise quotes the buffer
                    // it was handed, which here is the whole header text.
                    $bytes = self::fromBase64($matches['data'][0], substr($text, $matches['data'][1]));
                } elseif (strcasecmp($encoding, 'Q') === 0) {
                    $bytes = self::fromMime2QuotedPrintable($matches['data'][0]);
                } else {
                    // mime2_decode() knows B and Q; every other encoding is a
                    // syntax error, handled like any other one below.
                    $bytes = false;
                }

                if ($bytes === false) {
                    $failed = true;

                    return '';
                }

                if (strcasecmp($charset, 'UTF-8') === 0 || strcasecmp($charset, 'US-ASCII') === 0) {
                    return $bytes;
                }

                $converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);

                return $converted !== false ? $converted : $bytes;
            },
            $text,
            -1,
            $count,
            PREG_OFFSET_CAPTURE
        );

        // A word that will not decode voids the whole call: utf8_mime2text()
        // answers with src as it stands, throwing away the words it had
        // already converted before reaching the broken one.
        return $failed || $decoded === null ? $text : $decoded;
    }

    /**
     * Structured counterpart to decode(): splits the header value into an
     * ordered list of {charset, text} segments instead of concatenating
     * them, matching imap_mime_header_decode(). Unlike decode(), consecutive
     * encoded-words are NOT joined into one — each stays a separate segment.
     *
     * @return \stdClass[]|false
     */
    public static function decodeSegments(string $text): array|false
    {
        // c-client accepts any single character as the encoding and only
        // acts on B and Q; anything else leaves the data untouched but still
        // produces a segment carrying the charset.
        //
        // The charset may hold a space here, where decode() — imap_utf8()'s
        // side — will not touch such a word at all. The two functions really
        // do differ on this in the real extension.
        $pattern = '/=\?(?P<charset>[^?]+)\?(?P<encoding>[^?])\?(?P<data>[^?]*)\?=/';

        $segments = [];
        $cursor = 0;

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => [$fullMatch, $offset]) {
                if ($offset > $cursor) {
                    $gap = substr($text, $cursor, $offset - $cursor);

                    // Whitespace between two encoded words is folding, not
                    // content (RFC 2047 6.2), and belongs to neither. Only
                    // there: the spaces bordering ordinary text survive, and
                    // so does a leading or a trailing run.
                    if ($index === 0 || trim($gap, " \t\r\n") !== '') {
                        $segments[] = self::segment('default', $gap);
                    }
                }

                $charset = $matches['charset'][$index][0];
                $encoding = $matches['encoding'][$index][0];
                $data = $matches['data'][$index][0];

                if (strcasecmp($encoding, 'B') === 0) {
                    // php_imap.c hands rfc822_base64() the word's data alone,
                    // so that is what a warning from it quotes.
                    $bytes = self::fromBase64($data);

                    // One undecodable segment fails the whole call, as in
                    // php_imap.c where rfc822_base64() returning NIL aborts.
                    if ($bytes === false) {
                        return false;
                    }
                } elseif (strcasecmp($encoding, 'Q') === 0) {
                    // rfc822_qprint(), not the stricter reader inside an
                    // encoded word that decode() uses: a "=" with no hex pair
                    // behind it is reported and read past, not refused. The
                    // underscores become spaces first, in php_imap.c and so
                    // in what the report quotes.
                    $bytes = self::fromQuotedPrintable(str_replace('_', ' ', $data));
                } else {
                    $bytes = $data;
                }

                $segments[] = self::segment($charset, $bytes);
                $cursor = $offset + strlen($fullMatch);
            }
        }

        if ($cursor < strlen($text)) {
            $segments[] = self::segment('default', substr($text, $cursor));
        }

        // Empty in, empty out: c-client's rfc822_parse_mime_header walks the
        // text and emits a part per run it finds, so no text is no parts —
        // not one part holding nothing.
        return $segments;
    }

    /**
     * c-client's rfc822_base64(): the alphabet, plus the whitespace it skips,
     * and nothing else — any other byte refuses the whole string. A "=" is
     * padding only at the end of a quantum: one is enough in the fourth
     * position, in the third it has to be followed immediately by a second,
     * and anywhere else it is that same syntax error. A quantum left
     * incomplete with no padding at all is fine, and its spare bits are
     * dropped. Data *after* complete padding is not an error either: what was
     * read is kept and a warning goes on the stack.
     *
     * @param string|null $context the buffer the warning quotes from, whose
     *                             offsets are $data's — the whole header for
     *                             imap_utf8(), the word's data on its own for
     *                             imap_mime_header_decode()
     */
    public static function fromBase64(string $data, ?string $context = null): string|false
    {
        $context ??= $data;
        $bytes = '';
        $quantum = 0;
        $position = 0;
        $length = strlen($data);

        for ($index = 0; $index < $length; $index++) {
            $char = $data[$index];

            if (strpos(self::BASE64_WHITESPACE, $char) !== false) {
                continue;
            }

            if ($char === '=') {
                if ($position === 2 && ($data[$index + 1] ?? '') === '=') {
                    $position = 3;

                    continue;
                }

                if ($position !== 3) {
                    return false;
                }

                self::warnOnDataAfterPadding($data, $index + 1, $context);

                return $bytes;
            }

            $value = strpos(self::BASE64_ALPHABET, $char);

            if ($value === false) {
                return false;
            }

            switch ($position) {
                case 0:
                    $quantum = ($value << 2) & 0xFF;
                    break;
                case 1:
                    $bytes .= chr($quantum | ($value >> 4));
                    $quantum = ($value << 4) & 0xFF;
                    break;
                case 2:
                    $bytes .= chr($quantum | ($value >> 2));
                    $quantum = ($value << 6) & 0xFF;
                    break;
                default:
                    $bytes .= chr($quantum | $value);
                    break;
            }

            $position = $position === 3 ? 0 : $position + 1;
        }

        return $bytes;
    }

    private static function warnOnDataAfterPadding(string $data, int $offset, string $context): void
    {
        for ($index = $offset, $length = strlen($data); $index < $length; $index++) {
            $char = $data[$index];

            if ($char === '=' || strpos(self::BASE64_WHITESPACE, $char) !== false) {
                continue;
            }

            if (strpos(self::BASE64_ALPHABET, $char) === false) {
                continue;
            }

            $message = 'Possible data truncation in rfc822_base64(): '.substr($context, $index, 80);
            $break = strcspn($message, "\r\n");

            ErrorStack::push(substr($message, 0, $break));

            return;
        }
    }

    /**
     * The quoted-printable mime2_decode() reads inside an encoded word, which
     * is not rfc822_qprint(): there is no soft line break here, "_" is a
     * space, and a "=" without two hex digits behind it is a syntax error
     * that refuses the word rather than something to report and read past.
     */
    private static function fromMime2QuotedPrintable(string $data): string|false
    {
        $bytes = '';
        $length = strlen($data);

        for ($index = 0; $index < $length; $index++) {
            $char = $data[$index];

            if ($char === '_') {
                $bytes .= ' ';

                continue;
            }

            if ($char !== '=') {
                $bytes .= $char;

                continue;
            }

            $pair = substr($data, $index + 1, 2);

            if (strlen($pair) !== 2 || ctype_xdigit($pair) === false) {
                return false;
            }

            $bytes .= chr((int) hexdec($pair));
            $index += 2;
        }

        return $bytes;
    }

    private static function segment(string $charset, string $text): \stdClass
    {
        $segment = new \stdClass();
        $segment->charset = $charset;
        $segment->text = $text;

        return $segment;
    }
}
