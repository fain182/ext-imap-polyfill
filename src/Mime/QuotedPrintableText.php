<?php

namespace ImapPolyfill\Mime;

/**
 * Port of c-client's rfc822_8bit() (rfc822.c): quoted-printable with CRLF
 * pairs passed through verbatim, controls/DEL/8-bit/'='/space-before-CR
 * hex-quoted, and "=\r\n" soft breaks keeping lines within 75 characters.
 * Differs from PHP's quoted_printable_encode() in the details that real
 * ext-imap exhibits: TAB is quoted, a space is quoted only when a CR
 * follows (not at end of input), and soft-break positions.
 */
final class QuotedPrintableText
{
    private const MAX_LINE = 75;

    private const HEX = '0123456789ABCDEF';

    public static function encode(string $data): string
    {
        $result = '';
        $lineLength = 0;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $char = $data[$i];
            if ($char === "\r" && ($data[$i + 1] ?? '') === "\n") {
                $result .= "\r\n";
                $i++;
                $lineLength = 0;
                continue;
            }

            $byte = ord($char);
            if ($byte < 0x20 || $byte === 0x7f || $byte >= 0x80 || $char === '='
                || ($char === ' ' && ($data[$i + 1] ?? '') === "\r")) {
                $lineLength += 3;
                if ($lineLength > self::MAX_LINE) {
                    $result .= "=\r\n";
                    $lineLength = 3;
                }
                $result .= '='.self::HEX[$byte >> 4].self::HEX[$byte & 0xF];
            } else {
                if (++$lineLength > self::MAX_LINE) {
                    $result .= "=\r\n";
                    $lineLength = 1;
                }
                $result .= $char;
            }
        }

        return $result;
    }
}
