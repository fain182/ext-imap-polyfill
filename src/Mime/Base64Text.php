<?php

namespace ImapPolyfill\Mime;

/**
 * c-client's rfc822_binary(): base64 with a CRLF after every 60 output
 * characters plus one unconditional final CRLF — which produces a trailing
 * blank line whenever the encoded text is an exact multiple of 60 chars.
 */
final class Base64Text
{
    public static function encode(string $data): string
    {
        $result = '';
        foreach (str_split(base64_encode($data), 60) as $line) {
            $result .= $line;
            if (strlen($line) === 60) {
                $result .= "\r\n";
            }
        }

        return $result."\r\n";
    }
}
