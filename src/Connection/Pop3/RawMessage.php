<?php

namespace ImapPolyfill\Connection\Pop3;

/**
 * A RETR'd message, split into header and body once at construction time.
 * Shared by every POP3-side consumer that needs the split (Pop3Backend's
 * own FETCH emulation, Pop3MimeStructure, Pop3SearchEvaluator).
 */
final class RawMessage
{
    private readonly string $header;

    private readonly string $body;

    public function __construct(private readonly string $raw)
    {
        $pos = preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;

        if ($pos === null) {
            $this->header = $raw;
            $this->body = '';
        } else {
            $this->header = substr($raw, 0, $pos);
            $this->body = substr($raw, $pos + strlen($m[0][0]));
        }
    }

    public function getRaw(): string
    {
        return $this->raw;
    }

    public function getHeader(): string
    {
        return $this->header;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
