<?php

namespace Fain182\ImapPolyfill;

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
            '/=\?(?P<charset>[^?\s]+)\?(?P<encoding>[BbQq])\?(?P<data>[^?]*)\?=(?:\s+(?==\?[^?\s]+\?[BbQq]\?))?/',
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
}
