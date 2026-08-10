<?php

namespace ImapPolyfill\Message;

/**
 * The individual fields (To, From, Subject...) of a raw RFC822 header block,
 * read the way rfc822_parse_msg_full() reads them — which is not the way a
 * regular expression over "name: value" lines reads them.
 *
 * Three of its rules are load-bearing and none of them are obvious. A tab is
 * coerced to a space, wherever it stands. The value keeps its trailing
 * whitespace and loses only the blanks after the colon. And the block ends
 * at the first line that begins with a bare LF — but a CRLF blank line ends
 * nothing, so a header block written with CRLF and followed by a body is
 * read straight into the body, exactly as c-client reads it.
 *
 * A header written twice is kept twice, in the order it was written: for
 * everything but an address list c-client takes the first and ignores the
 * rest, and for an address list it appends, so both answers have to be
 * available here.
 */
final class RawHeaderFields
{
    /**
     * @param list<array{0: string, 1: string}> $lines lowercase name and value, in the order written
     */
    private function __construct(private readonly array $lines)
    {
    }

    public static function parse(string $rawHeader): self
    {
        $lines = [];
        $length = strlen($rawHeader);
        $index = 0;

        while ($index < $length && $rawHeader[$index] !== "\n") {
            $line = '';

            while ($index < $length) {
                $char = $rawHeader[$index++];

                // A CR is a line ending only when no LF follows it; the pair
                // is left to the LF below.
                if ($char === "\r" && ($rawHeader[$index] ?? '') === "\n") {
                    continue;
                }

                if ($char === "\r" || $char === "\n") {
                    // The line ends here unless the next one starts with
                    // whitespace, in which case the break disappears and the
                    // whitespace joins the value.
                    $next = $rawHeader[$index] ?? '';

                    if ($next !== ' ' && $next !== "\t") {
                        break;
                    }

                    continue;
                }

                $line .= $char === "\t" ? ' ' : $char;
            }

            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $lines[] = [
                strtolower(rtrim(substr($line, 0, $colon), ' ')),
                ltrim(substr($line, $colon + 1), ' '),
            ];
        }

        return new self($lines);
    }

    /**
     * Every header line, in the order it was written. The order is not
     * decoration: c-client parses each address header where it stands, so it
     * is the order the complaints of two malformed headers reach the error
     * stack in.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /** What c-client keeps for a header it stores as text: the first one written. */
    public function first(string $name): ?string
    {
        foreach ($this->lines as [$header, $value]) {
            if ($header === $name) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The first value of every header, for the readers that want a plain map
     * — MIME part headers, where a repeated header is not a list to join.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $fields = [];

        foreach ($this->lines as [$header, $value]) {
            $fields[$header] ??= $value;
        }

        return $fields;
    }
}
