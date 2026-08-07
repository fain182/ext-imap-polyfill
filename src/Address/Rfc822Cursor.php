<?php

namespace ImapPolyfill\Address;

/**
 * A cursor over RFC 822 header text, moving the way c-client's rfc822.c
 * moves through it.
 *
 * The two operations that matter are the ones a regular expression cannot
 * express. Comments nest, so finding where one ends means counting depth,
 * not matching a pattern; and whitespace has to be skipped *with* them,
 * since c-client's rfc822_skipws() treats the two alike. Everything the
 * address parser does is phrased in terms of those.
 */
final class Rfc822Cursor
{
    /** RFC 822 specials: the characters an atom may not contain. */
    private const SPECIALS = '()<>@,;:\\".[]';

    private int $position = 0;

    private ?string $lastComment = null;

    public function __construct(private readonly string $source)
    {
    }

    /**
     * The contents of the comment most recently skipped, brackets off.
     * RFC 822's other way of writing a name is to put it in a comment after
     * the address — "joe@example.com (Joe Doe)" — so what skipws stepped
     * over is not always something to forget.
     */
    public function lastComment(): ?string
    {
        return $this->lastComment;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function atEnd(): bool
    {
        return $this->position >= strlen($this->source);
    }

    public function peek(): ?string
    {
        return $this->source[$this->position] ?? null;
    }

    public function skip(int $count = 1): void
    {
        $this->position += $count;
    }

    public function slice(int $start, int $end): string
    {
        return substr($this->source, $start, $end - $start);
    }

    /**
     * rfc822_skipws(): whitespace and comments are equally invisible
     * between tokens. A comment may nest, may hold a quote that opens
     * nothing, and may hold a backslash that escapes the next character —
     * including the parenthesis that would otherwise close it.
     */
    public function skipWhitespaceAndComments(): void
    {
        $length = strlen($this->source);

        while ($this->position < $length) {
            $char = $this->source[$this->position];

            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
                ++$this->position;

                continue;
            }

            if ($char !== '(') {
                return;
            }

            $depth = 0;
            $start = $this->position;
            $closed = false;

            while ($this->position < $length) {
                $char = $this->source[$this->position];

                if ($char === '\\') {
                    $this->position += 2;

                    continue;
                }

                ++$this->position;

                if ($char === '(') {
                    ++$depth;
                } elseif ($char === ')' && --$depth === 0) {
                    $closed = true;

                    break;
                }
            }

            // A comment that never closes has swallowed the rest of the
            // input, so whatever it holds was not written as a name.
            $this->lastComment = $closed
                ? substr($this->source, $start + 1, $this->position - $start - 2)
                : null;
        }
    }

    /**
     * rfc822_parse_word(): one quoted string or one atom, whichever is
     * next. Returns the position just past it, or null where neither
     * starts — which is how the caller learns a phrase has ended.
     *
     * A quoted string that never closes is not a word: everything after it
     * was going to be read as part of the name, so the address it was
     * attached to is unreachable and the whole entry is malformed.
     */
    public function readWord(): ?int
    {
        $length = strlen($this->source);

        if ($this->position >= $length) {
            return null;
        }

        // A word read past the comment means the comment was not the
        // trailing one, and cannot stand in as a personal name.
        $this->lastComment = null;

        if ($this->source[$this->position] === '"') {
            ++$this->position;

            while ($this->position < $length) {
                $char = $this->source[$this->position];

                if ($char === '\\') {
                    $this->position += 2;

                    continue;
                }

                ++$this->position;

                if ($char === '"') {
                    return $this->position;
                }
            }

            return null;
        }

        $start = $this->position;

        while ($this->position < $length) {
            $char = $this->source[$this->position];

            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n"
                || str_contains(self::SPECIALS, $char)) {
                break;
            }

            ++$this->position;
        }

        return $this->position > $start ? $this->position : null;
    }

    /**
     * rfc822_cpy(): the text as it reads once the quoting is taken off —
     * quote characters dropped, backslash escapes resolved. Applied to a
     * whole phrase rather than a single word, so `"a" "b"` becomes `a b`
     * and a comment that fell between two words survives verbatim.
     */
    public static function unquote(string $text): string
    {
        $result = '';
        $length = strlen($text);
        $inQuotes = false;

        for ($index = 0; $index < $length; ++$index) {
            $char = $text[$index];

            if ($char === '"') {
                $inQuotes = !$inQuotes;

                continue;
            }

            if ($inQuotes && $char === '\\' && $index + 1 < $length) {
                $result .= $text[++$index];

                continue;
            }

            $result .= $char;
        }

        return $result;
    }
}
