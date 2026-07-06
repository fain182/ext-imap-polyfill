<?php

namespace Fain182\ImapPolyfill;

/**
 * Parses the parenthesized-list syntax IMAP servers use for structured FETCH
 * data items (e.g. BODYSTRUCTURE, ENVELOPE): atoms, quoted strings, NIL,
 * numbers and nested lists.
 *
 * Written from scratch instead of reusing webklex's response tokenizer, which
 * mis-parses quoted strings immediately followed by a closing paren with no
 * space (e.g. `("charset" "us-ascii") NIL`), a pattern real IMAP servers emit
 * routinely in BODYSTRUCTURE responses.
 */
final class ImapSexpParser
{
    private function __construct(
        private readonly string $buffer,
        private int $pos,
    ) {
    }

    /**
     * @return array<int, mixed>
     */
    public static function parseAt(string $buffer, int $pos): array
    {
        $parser = new self($buffer, $pos);

        return $parser->parseList();
    }

    /**
     * @return array<int, mixed>
     */
    private function parseList(): array
    {
        $this->pos++; // consume '('

        $items = [];
        while (true) {
            $this->skipWhitespace();

            if (($this->buffer[$this->pos] ?? null) === ')') {
                $this->pos++;
                break;
            }

            $items[] = $this->parseValue();
        }

        return $items;
    }

    private function parseValue(): mixed
    {
        $this->skipWhitespace();
        $char = $this->buffer[$this->pos] ?? null;

        return match (true) {
            $char === '(' => $this->parseList(),
            $char === '"' => $this->parseQuoted(),
            $char === '{' => $this->parseLiteral(),
            default => $this->parseAtom(),
        };
    }

    private function parseQuoted(): string
    {
        $this->pos++; // consume opening quote
        $result = '';

        while (($char = $this->buffer[$this->pos] ?? null) !== '"') {
            if ($char === null) {
                throw new \RuntimeException('Unterminated quoted string in IMAP response');
            }

            if ($char === '\\') {
                $this->pos++;
                $result .= $this->buffer[$this->pos];
            } else {
                $result .= $char;
            }

            $this->pos++;
        }

        $this->pos++; // consume closing quote

        return $result;
    }

    private function parseLiteral(): string
    {
        $closingBrace = strpos($this->buffer, '}', $this->pos);
        $length = (int) substr($this->buffer, $this->pos + 1, $closingBrace - $this->pos - 1);

        $start = $closingBrace + 1;
        if (($this->buffer[$start] ?? '') === "\r") {
            $start++;
        }
        if (($this->buffer[$start] ?? '') === "\n") {
            $start++;
        }

        $value = substr($this->buffer, $start, $length);
        $this->pos = $start + $length;

        return $value;
    }

    private function parseAtom(): int|string|null
    {
        $start = $this->pos;

        while (($char = $this->buffer[$this->pos] ?? null) !== null && !str_contains(" ()\r\n", $char)) {
            $this->pos++;
        }

        $atom = substr($this->buffer, $start, $this->pos - $start);

        if (strcasecmp($atom, 'NIL') === 0) {
            return null;
        }

        if (preg_match('/^\d+$/', $atom)) {
            return (int) $atom;
        }

        return $atom;
    }

    private function skipWhitespace(): void
    {
        while (($char = $this->buffer[$this->pos] ?? null) !== null && str_contains(" \r\n", $char)) {
            $this->pos++;
        }
    }
}
