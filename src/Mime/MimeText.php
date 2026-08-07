<?php

namespace ImapPolyfill\Mime;

final class MimeText
{
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
        $decoded = preg_replace_callback(
            // RFC 2047 lets no whitespace inside an encoded word, and
            // c-client holds the line: a "word" with a space in its payload
            // is left standing as the text it evidently is.
            '/=\?(?P<charset>[^?\s]+)\?(?P<encoding>[BbQq])\?(?P<data>[^?\s]*)\?=(?:\s+(?==\?[^?\s]+\?[BbQq]\?))?/',
            static function (array $matches): string {
                $charset = $matches['charset'];
                $bytes = strcasecmp($matches['encoding'], 'B') === 0
                    ? base64_decode($matches['data'])
                    : quoted_printable_decode(str_replace('_', ' ', $matches['data']));

                if (strcasecmp($charset, 'UTF-8') === 0 || strcasecmp($charset, 'US-ASCII') === 0) {
                    return $bytes;
                }

                $converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);

                return $converted !== false ? $converted : $bytes;
            },
            $text
        );

        return $decoded ?? $text;
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
                    $bytes = base64_decode($data, true);

                    // One undecodable segment fails the whole call, as in
                    // php_imap.c where rfc822_base64() returning NIL aborts.
                    if ($bytes === false) {
                        return false;
                    }
                } elseif (strcasecmp($encoding, 'Q') === 0) {
                    $bytes = quoted_printable_decode(str_replace('_', ' ', $data));
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

        return $segments === [] ? [self::segment('default', $text)] : $segments;
    }

    private static function segment(string $charset, string $text): \stdClass
    {
        $segment = new \stdClass();
        $segment->charset = $charset;
        $segment->text = $text;

        return $segment;
    }
}
