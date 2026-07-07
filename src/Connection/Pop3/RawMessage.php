<?php

namespace ImapPolyfill\Connection\Pop3;

/**
 * Splits a raw RETR'd message into its header block and body, shared by
 * every POP3-side consumer that needs the split (Pop3Backend's own FETCH
 * emulation, Pop3MimeStructure, Pop3SearchEvaluator).
 */
final class RawMessage
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function splitHeaderBody(string $raw): array
    {
        $pos = preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;

        if ($pos === null) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $pos), substr($raw, $pos + strlen($m[0][0]))];
    }
}
