<?php

namespace ImapPolyfill\Connection\Imap;

use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Connection\Tokens\EmailAddress;
use DirectoryTree\ImapEngine\Connection\Tokens\Nil;
use DirectoryTree\ImapEngine\Connection\Tokens\QuotedString;
use DirectoryTree\ImapEngine\Exceptions\ImapParserException;
use DirectoryTree\ImapEngine\Exceptions\ImapStreamException;

/**
 * ImapEngine's tokenizer, reading the runs inside a token a run at a time.
 *
 * The parent takes a quoted string, an atom or a run of blanks one byte per
 * call of ensureBuffer(), currentChar() and advance(), which is most of the
 * CPU a FETCH ENVELOPE over a large folder costs. These overrides take
 * whatever the buffer already holds up to the next byte that matters in one
 * strcspn() or strspn(), and keep the parent's buffer handling, tokens and
 * exceptions as they are.
 */
final class ImapEngineTokenizer extends ImapTokenizer
{
    /** What isValidAtomCharacter() rejects: controls, DEL and isDelimiter()'s list. */
    private const NOT_ATOM = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F"
        ."\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F ()[]{}<>";

    protected function skipWhitespace(): void
    {
        while (true) {
            $this->ensureBuffer(1);
            $run = strspn($this->buffer, " \t", $this->position);

            if ($run === 0) {
                return;
            }

            $this->advance($run);
        }
    }

    protected function readQuotedString(): QuotedString
    {
        // Skip the opening quote.
        $this->advance();

        $value = '';

        while (true) {
            $this->ensureBuffer(1);
            $run = strcspn($this->buffer, '"\\', $this->position);

            if ($run > 0) {
                $value .= substr($this->buffer, $this->position, $run);
                $this->advance($run);

                continue;
            }

            $char = $this->currentChar();

            if ($char === null) {
                throw new ImapParserException(sprintf(
                    'Unterminated quoted string at buffer offset %d. Buffer: "%s"',
                    $this->position,
                    substr($this->buffer, max(0, $this->position - 10), 20)
                ));
            }

            if ($char === '\\') {
                $this->advance();
                $this->ensureBuffer(1);

                $escapedChar = $this->currentChar()
                    ?? throw new ImapParserException('Unterminated escape sequence in quoted string');

                $value .= $escapedChar;
                $this->advance();

                continue;
            }

            // The closing quote.
            $this->advance();

            return new QuotedString($value);
        }
    }

    protected function readAtom(): Atom
    {
        $value = '';

        while (true) {
            $this->ensureBuffer(1);
            $run = strcspn($this->buffer, self::NOT_ATOM, $this->position);

            if ($run === 0) {
                break;
            }

            $value .= substr($this->buffer, $this->position, $run);
            $this->advance($run);
        }

        if ($value === '') {
            $char = $this->currentChar()
                ?? throw new ImapStreamException('Unexpected end of stream while reading atom');

            throw new ImapParserException(sprintf(
                'Unexpected byte 0x%02X in response at buffer offset %d',
                ord($char),
                $this->position
            ));
        }

        if (strcasecmp($value, 'NIL') === 0) {
            return new Nil($value);
        }

        return new Atom($value);
    }

    protected function readEmailAddress(): EmailAddress
    {
        $value = '';

        while (true) {
            $this->ensureBuffer(1);
            $run = strcspn($this->buffer, '>', $this->position);

            if ($run > 0) {
                $value .= substr($this->buffer, $this->position, $run);
                $this->advance($run);

                continue;
            }

            if ($this->currentChar() === null) {
                throw new ImapParserException('Unterminated email address, expected ">"');
            }

            // The closing '>'.
            $this->advance();

            return new EmailAddress($value);
        }
    }
}
