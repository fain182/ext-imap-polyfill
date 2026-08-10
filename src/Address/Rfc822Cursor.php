<?php

namespace ImapPolyfill\Address;

use ImapPolyfill\Support\ErrorStack;

/**
 * A cursor over RFC 822 header text, moving the way c-client's rfc822.c
 * moves through it.
 *
 * The three operations that matter are the ones a regular expression cannot
 * express. Comments nest, so finding where one ends means recursion, not
 * matching a pattern; whitespace has to be skipped *with* them, since
 * rfc822_skipws() treats the two alike; and a word may hold a quoted string
 * in the middle of it. Everything the address parser does is phrased in
 * terms of those.
 *
 * The text is a buffer rather than a value because c-client writes into it:
 * an unterminated comment is reported once and its "(" overwritten with a
 * NUL, so a re-scan of the same text cannot report it again. Everything the
 * parse does afterwards reads the shortened buffer.
 */
final class Rfc822Cursor
{
    /** The control characters c-client's specials tables all carry. */
    private const CONTROLS = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        ."\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /**
     * c-client's wspecials, the delimiters rfc822_parse_word() reads a word
     * up to. The dot is not one of them: a word may hold dots, and where
     * they are significant — between the parts of a mailbox or a domain —
     * the caller looks for them itself.
     */
    public const WORD_SPECIALS = " ()<>@,;:\\\"[]".self::CONTROLS;

    /**
     * The two a domain literal is read up to. Neither the quote nor the
     * space is among them, so both are ordinary text inside brackets.
     */
    public const LITERAL_SPECIALS = "]\\";

    private int $position = 0;

    private bool $cancelled = false;

    public function __construct(private string $source)
    {
    }

    public function position(): int
    {
        return $this->position;
    }

    public function seek(int $position): void
    {
        $this->position = $position;
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

    /**
     * c-client keeps one parse pointer and abandons the rest of the input by
     * setting it to NIL — which is what an address list does after a
     * complaint, and what a group does when its contents will not parse. It
     * is not the same as having read everything: text may well be left.
     */
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    /**
     * Whether the parse gave up. Not the same question as atEnd(): a pointer
     * resting on the end of the text is still a pointer, and c-client tells
     * the two apart — a list ending in a comma reaches its parser with
     * nothing left to read, and that is what earns "Missing address after
     * comma" rather than silence.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /** Whatever is left unread, which is what c-client names in its complaint. */
    public function rest(): string
    {
        return substr($this->source, $this->position);
    }

    public function slice(int $start, int $end): string
    {
        return substr($this->source, $start, $end - $start);
    }

    /**
     * rfc822_skipws(): whitespace and comments are equally invisible
     * between tokens. A comment that never closes ends the skip where it
     * began — the pointer does not move past text nobody could read.
     */
    public function skipWhitespaceAndComments(): void
    {
        while (!$this->atEnd()) {
            $char = $this->source[$this->position];

            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
                ++$this->position;

                continue;
            }

            if ($char !== '(' || $this->skipComment(false) === null) {
                return;
            }
        }
    }

    /**
     * rfc822_skip_comment(): what the comment at the cursor holds, with the
     * cursor left just past it, or null where it never closes.
     *
     * Trimming is what a caller reading the comment as a personal name asks
     * for: the leading blanks are gone either way, and the trailing ones go
     * with it, since c-client ties the string off after the last character
     * that was not a blank.
     *
     * A comment that never closes is reported and its "(" overwritten, so a
     * caller that reads this text again does not report it a second time.
     * An unterminated comment *inside* one is why that matters: the inner is
     * reported here, and the outer — now unterminated itself, against the
     * shortened buffer — on the next pass, which is the order the two
     * complaints come out in.
     */
    public function skipComment(bool $trim): ?string
    {
        $length = strlen($this->source);
        $start = $this->position;
        $index = $start + 1;

        // Where the text of the comment begins: c-client steps over blanks
        // before it remembers the position, so they are never part of it.
        $contentStart = $index;

        while ($contentStart < $length && $this->source[$contentStart] === ' ') {
            ++$contentStart;
        }

        // The last character that was not a blank, which is where a trimmed
        // comment ends.
        $lastSignificant = null;

        while (true) {
            $char = $this->source[$index] ?? '';

            if ($char === '(') {
                $this->position = $index;

                if ($this->skipComment(false) === null) {
                    // Whoever asked is left looking at this comment's own
                    // "(", not at the inner one that gave up.
                    $this->position = $start;

                    return null;
                }

                // The nested comment's own ")" is the significant character.
                $lastSignificant = $index = $this->position - 1;
            } elseif ($char === ')') {
                $this->position = $index + 1;

                if (!$trim) {
                    return $this->slice($contentStart, $index);
                }

                return $lastSignificant === null
                    ? ''
                    : $this->slice($contentStart, $lastSignificant + 1);
            } elseif ($char === '\\' && isset($this->source[$index + 1])) {
                $lastSignificant = ++$index;
            } elseif ($char === '' || ($char === '\\' && !isset($this->source[$index + 1]))) {
                ErrorStack::push('Unterminated comment: '.substr($this->source, $start, 80));
                $this->source = substr($this->source, 0, $start);
                $this->position = $start;

                return null;
            } elseif ($char !== ' ') {
                $lastSignificant = $index;
            }

            ++$index;
        }
    }

    /**
     * rfc822_parse_word(): one atom, or one quoted string, or a run of both
     * — the scan resumes after a closing quote, so `"a"b` is a single word.
     * Answers the position just past it, or null where no word starts here,
     * which is how a phrase learns it has ended.
     *
     * A quoted string that never closes is not a word: everything after it
     * was going to be read as part of the name, so the address it was
     * attached to is unreachable and the whole entry is malformed.
     *
     * The cursor moves to the end of the word, and stays where it was when
     * there is no word — the leading whitespace this skipped is skipped
     * again by whoever asks next.
     */
    public function readWord(string $delimiters = self::WORD_SPECIALS): ?int
    {
        $entry = $this->position;
        $this->skipWhitespaceAndComments();

        if ($this->atEnd()) {
            $this->position = $entry;

            return null;
        }

        $length = strlen($this->source);
        $start = $this->position;
        $scan = $start;

        while (true) {
            $stop = self::firstOf($this->source, $delimiters, $scan);

            if ($stop === null) {
                return $this->position = $length;
            }

            $char = $this->source[$stop];

            if ($char === '"') {
                $index = $stop;

                while (true) {
                    // The closing quote is looked for before anything else,
                    // so a backslash inside the string hides the quote that
                    // follows it rather than being hidden by it.
                    if (++$index >= $length) {
                        $this->position = $entry;

                        return null;
                    }

                    if ($this->source[$index] === '"') {
                        break;
                    }

                    if ($this->source[$index] === '\\' && ++$index >= $length) {
                        $this->position = $entry;

                        return null;
                    }
                }

                $scan = $index + 1;

                continue;
            }

            // A backslash quotes the character behind it here as much as
            // inside a quoted string. c-client calls that "pretty
            // pathological" and reads past both anyway.
            if ($char === '\\' && $stop + 1 < $length) {
                $scan = $stop + 2;

                continue;
            }

            if ($stop === $start) {
                $this->position = $entry;

                return null;
            }

            return $this->position = $stop;
        }
    }

    /** strpbrk() from an offset: the first position holding one of the delimiters. */
    private static function firstOf(string $text, string $delimiters, int $from): ?int
    {
        $length = strlen($text);

        for ($index = $from; $index < $length; ++$index) {
            if (str_contains($delimiters, $text[$index])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * rfc822_parse_phrase(): words, and the whitespace and comments between
     * them, up to whatever ends the phrase. Answers where the last word
     * ended — which is where the phrase ends, never at the trailing
     * whitespace or comment the scan had to look through to find that out.
     */
    public function readPhrase(): ?int
    {
        $end = $this->readWord();

        if ($end === null) {
            return null;
        }

        while (!$this->atEnd()) {
            $this->skipWhitespaceAndComments();
            $next = $this->readWord();

            if ($next === null) {
                break;
            }

            $end = $next;
        }

        $this->position = $end;

        return $end;
    }

    /**
     * rfc822_cpy(): the text as it reads once the quoting is taken off —
     * every quote character dropped, every backslash taken as quoting the
     * character behind it. c-client does not track whether it is inside a
     * quoted string when it does this, and neither does this: `=\=` is `==`
     * to the extension, quotes or no quotes.
     *
     * Applied to a whole phrase rather than a single word, so `"a" "b"`
     * becomes `a b` and a comment that fell between two words survives.
     */
    public static function unquote(string $text): string
    {
        if (strpbrk($text, '\\"') === false) {
            return $text;
        }

        $result = '';
        $length = strlen($text);

        for ($index = 0; $index < $length; ++$index) {
            $char = $text[$index];

            if ($char === '"') {
                continue;
            }

            if ($char === '\\' && $index + 1 < $length) {
                $result .= $text[++$index];

                continue;
            }

            $result .= $char;
        }

        return $result;
    }
}
