<?php

namespace ImapPolyfill\Connection\Pop3;

use ImapPolyfill\Address\Rfc822Cursor;
use ImapPolyfill\Message\BodyPart;
use ImapPolyfill\Support\ErrorStack;

/**
 * c-client's BODY for a message POP3 handed over whole. POP3 has no
 * BODYSTRUCTURE, so c-client parses the RETR'd text itself with
 * rfc822_parse_msg_full() and rfc822_parse_content(), and this is those two
 * ported: the same single pass over the bytes, the same counters, so a
 * boundary is found where theirs is found and missed where theirs is
 * missed. Each body keeps where its contents and its MIME header lie in the
 * message, which is how mail_fetch_body() and mail_fetch_mime() answer a
 * section without parsing anything again.
 *
 * The parse logs what c-client logs at PARSE level, which php_imap.c's
 * mm_log() puts on the error stack.
 */
final class MimeBody
{
    /** rfc822.c's tspecials: what a MIME word (type, subtype, parameter) ends at. */
    private const TSPECIALS = " ()<>@,;:\\\"[]/?=\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        ."\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /** body_types[] as c-client starts out with it. */
    private const TYPES = ['TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'MODEL', 'X-UNKNOWN'];

    /** body_encodings[] as c-client starts out with it. */
    private const ENCODINGS = ['7BIT', '8BIT', 'BINARY', 'BASE64', 'QUOTED-PRINTABLE', 'X-UNKNOWN'];

    /** The encodings a multipart or an embedded message may carry (anything else is ignored with a log). */
    private const IDENTITY_ENCODINGS = ['7BIT', '8BIT', 'BINARY'];

    /** rfc822.h's MAXMIMEDEPTH. */
    private const MAX_DEPTH = 50;

    private string $type = 'TEXT';

    private ?string $subtype = null;

    /** @var list<array{0: string, 1: string}> */
    private array $parameters = [];

    private ?string $id = null;

    private ?string $description = null;

    private string $encoding = '7BIT';

    private ?string $disposition = null;

    /** @var list<array{0: string, 1: string}> */
    private array $dispositionParameters = [];

    private int $offset = 0;

    private int $size = 0;

    private int $lines = 0;

    private int $mimeOffset = 0;

    private int $mimeSize = 0;

    /** @var list<self> */
    private array $parts = [];

    /** The body of a message/rfc822 part's own message; null for an empty one in a digest. */
    private ?self $message = null;

    private function __construct(private readonly string $raw)
    {
    }

    public static function parse(RawMessage $message): self
    {
        $raw = $message->getRaw();
        $body = new self($raw);
        $body->parseMessage(0, strlen($raw) - strlen($message->getBody()), strlen($raw), 0);

        return $body;
    }

    /**
     * mail_body(): the body a section of part numbers names, or null where
     * it names none.
     */
    public function section(string $section): ?self
    {
        $body = $this;
        $segments = explode('.', $section);

        foreach ($segments as $index => $segment) {
            if (!ctype_digit($segment) || (int) $segment === 0) {
                return null;
            }

            if ($body->type === 'MULTIPART') {
                $body = $body->parts[(int) $segment - 1] ?? null;
                if ($body === null) {
                    return null;
                }
            } elseif ((int) $segment !== 1) {
                return null;
            }

            if ($index === count($segments) - 1) {
                break;
            }

            if ($body->type === 'MESSAGE' && $body->subtype === 'RFC822') {
                $body = $body->message;
                if ($body === null) {
                    return null;
                }
            } elseif ($body->type !== 'MULTIPART') {
                return null;
            }
        }

        return $body;
    }

    public function text(): string
    {
        return (string) substr($this->raw, $this->offset, $this->size);
    }

    /** The part's own header, blank line included; empty for the message's top-level body. */
    public function mimeText(): string
    {
        return (string) substr($this->raw, $this->mimeOffset, $this->mimeSize);
    }

    public function toBodyPart(): BodyPart
    {
        if ($this->type === 'MULTIPART') {
            $parts = array_map(static fn (self $part): BodyPart => $part->toBodyPart(), $this->parts);
        } elseif ($this->message !== null) {
            $parts = [$this->message->toBodyPart()];
        } else {
            $parts = [];
        }

        return new BodyPart(
            type: BodyPart::typeCode($this->type),
            encoding: BodyPart::encodingCode($this->encoding),
            subtype: $this->subtype,
            parameters: $this->parameters,
            id: $this->id,
            description: $this->description,
            bytes: $this->type === 'MULTIPART' ? 0 : $this->size,
            lines: $this->lines,
            disposition: $this->disposition,
            dispositionParameters: $this->dispositionParameters,
            parts: $parts,
        );
    }

    /**
     * rfc822_parse_msg_full(): the header's Content-* fields, read only when
     * the message is MIME, then the body behind it.
     */
    private function parseMessage(int $headerStart, int $headerLength, int $end, int $depth): void
    {
        $header = (string) substr($this->raw, $headerStart, $headerLength);
        $position = 0;
        $remaining = $headerLength;
        // -1 until a Content-* field makes it matter whether this is MIME.
        $mime = -1;

        while ($remaining > 0 && $header[$position] !== "\n") {
            $line = '';
            $char = ' ';

            while ($remaining > 0 && $char !== "\0") {
                $char = $header[$position++];

                switch ($char) {
                    case "\r":
                        if (($header[$position] ?? "\0") === "\n") {
                            break;
                        }
                        // A CR alone ends the line like an LF does.
                        // no break
                    case "\n":
                        $next = $header[$position] ?? "\0";
                        if ($next !== ' ' && $next !== "\t") {
                            $char = "\0";
                        }
                        break;
                    case "\t":
                        $line .= ' ';
                        break;
                    default:
                        $line .= $char;
                        break;
                }

                --$remaining;
            }

            $line = explode("\0", $line, 2)[0];
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $name = strtoupper(rtrim(substr($line, 0, $colon), ' '));
            $value = ltrim(substr($line, $colon + 1), ' ');

            if (str_starts_with($name, 'CONTENT-')) {
                $field = substr($name, 8);

                if ($mime === -1) {
                    // A MIME-Version still to come makes this MIME as much
                    // as one already seen; search() is case-blind.
                    $mime = stripos((string) substr($header, $position - 1, $remaining), "\nMIME-Version") !== false ? 1 : 0;

                    if ($mime === 0 && ($field === 'TRANSFER-ENCODING' || ($field === 'TYPE' && str_contains($value, '/')))) {
                        ErrorStack::push('Warning: MIME header encountered in non-MIME message');
                        $mime = 1;
                    }
                }

                if ($mime === 1) {
                    $this->parseContentHeader($field, $value);
                }
            } elseif ($name === 'MIME-VERSION') {
                $cursor = new Rfc822Cursor($value);
                $phraseEnd = $cursor->readPhrase();
                $version = new Rfc822Cursor($phraseEnd === null ? $value : substr($value, 0, $phraseEnd));
                $version->skipWhitespaceAndComments();

                if (!in_array($version->rest(), ['1.0', 'RFC-XXXX'], true)) {
                    ErrorStack::push('Warning: message has unknown MIME version');
                }

                $mime = 1;
            }
        }

        $this->parseContent($headerStart + $headerLength, $end, $depth);
    }

    /**
     * rfc822_parse_content(): the body's size and line count, its default
     * subtype and charset, and for a multipart or an embedded message the
     * bodies inside it.
     */
    private function parseContent(int $start, int $end, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->type = 'TEXT';
            ErrorStack::push('Ignoring excessively deep MIME recursion');
        }

        $this->subtype ??= self::defaultSubtype($this->type);
        $this->offset = $start;
        $this->size = $end - $start;

        if ($this->type === 'TEXT') {
            $this->parseText();
        } elseif ($this->type === 'MESSAGE') {
            if ($this->subtype === 'RFC822') {
                $this->parseEmbeddedMessage($depth);
            }
            $this->lines = substr_count($this->text(), "\n");
        } elseif ($this->type === 'MULTIPART') {
            $this->parseMultipart($depth);
        }
    }

    private function parseText(): void
    {
        if ($this->parameters !== []) {
            $this->lines = substr_count($this->text(), "\n");

            return;
        }

        // Counting the lines and guessing the charset in the same pass, the
        // way c-client does: the bytes an ISO-2022-JP escape consumes are
        // not looked at again, not even for a newline.
        $charset = null;
        $position = $this->offset;
        $remaining = $this->size;

        while ($remaining-- > 0) {
            $char = $this->raw[$position++];

            if ($charset === null) {
                if ($char === "\x1b" && $remaining > 0) {
                    --$remaining;
                    $char = $this->raw[$position++];

                    if ($char === '$' && $remaining > 0) {
                        --$remaining;
                        $char = $this->raw[$position++];

                        if ($char === 'B' || $char === '@') {
                            $charset = 'ISO-2022-JP';
                        }
                    }
                }

                if ($charset === null && (ord($char) & 0x80)) {
                    $charset = 'X-UNKNOWN';
                }
            }

            if ($char === "\n") {
                ++$this->lines;
            }
        }

        $charset ??= in_array($this->encoding, self::IDENTITY_ENCODINGS, true) ? 'US-ASCII' : 'X-UNKNOWN';
        $this->parameters = [['CHARSET', $charset]];
    }

    private function parseEmbeddedMessage(int $depth): void
    {
        if (!in_array($this->encoding, self::IDENTITY_ENCODINGS, true)) {
            ErrorStack::push('Ignoring nested encoding of message contents');
        }

        // The header ends at the first empty line, a CR before its LF
        // notwithstanding.
        $char = "\n";
        $headerLength = 0;
        $position = $this->offset;

        while ($this->size > $headerLength && ($char !== "\n" || ($this->raw[$position] ?? "\0") !== "\n")) {
            $next = $this->raw[$position++];
            if ($next !== "\r") {
                $char = $next;
            }
            ++$headerLength;
        }

        if ($this->size > $headerLength) {
            ++$headerLength;
        }

        $this->message = new self($this->raw);
        $this->message->parseMessage($this->offset, $headerLength, $this->offset + $this->size, $depth + 1);
    }

    /**
     * The multipart half of rfc822_parse_content(). A delimiter counts only
     * at the start of a line, followed by nothing but blanks; where the
     * closing one never comes, the last part runs to the end of the body.
     */
    private function parseMultipart(int $depth): void
    {
        if (!in_array($this->encoding, self::IDENTITY_ENCODINGS, true)) {
            ErrorStack::push('Ignoring nested encoding of multipart contents');
        }

        $digest = $this->subtype === 'DIGEST';
        $boundary = '-';
        foreach ($this->parameters as [$attribute, $value]) {
            if ($attribute === 'BOUNDARY') {
                $boundary = $value;
                break;
            }
        }

        $raw = $this->raw;
        $delimiterLength = strlen($boundary) + 2;
        $remaining = $this->size;
        $position = $this->offset;
        $char = "\n";
        $part = null;

        while ($remaining > $delimiterLength) {
            // Where the previous part ends if a delimiter starts on this line:
            // just before the line break that precedes it.
            $before = $position > 0 ? $position - 1 : 0;

            if ($char !== "\r" && $char !== "\n") {
                $char = $raw[$position++] ?? "\0";
                --$remaining;

                continue;
            }

            if ($char === "\r" && ($raw[$position] ?? "\0") === "\n") {
                $char = $raw[$position++];
                --$remaining;
            }

            // At the start of a line: is it "--" and the boundary? More than
            // the delimiter's length was left when this line began, so none
            // of these reads can run out of body.
            --$remaining;
            $char = $raw[$position++] ?? "\0";
            if ($char !== '-') {
                continue;
            }
            --$remaining;
            $char = $raw[$position++] ?? "\0";
            if ($char !== '-') {
                continue;
            }

            $unmatched = strlen($boundary);
            $index = 0;
            while ($unmatched > 0) {
                --$remaining;
                $char = $raw[$position++] ?? "\0";
                if ($char !== $boundary[$index++]) {
                    break;
                }
                --$unmatched;
            }
            if ($unmatched > 0) {
                continue;
            }

            $char = $this->nextOrNewline($position, $remaining);

            if ($char === '-') {
                // "--" again, then the end of the line or of the body: the
                // closing delimiter, and the scan stops.
                $char = $this->nextOrNewline($position, $remaining, "\0");
                if ($char === '-') {
                    $char = $this->nextOrNewline($position, $remaining);
                    if ($char === "\r" || $char === "\n") {
                        if ($part !== null) {
                            $part->mimeSize = max(0, $before - $part->mimeOffset);
                        }
                        $part = null;
                        $remaining = 1;
                    }
                }

                continue;
            }

            while ($char === ' ' || $char === "\t") {
                $char = $this->nextOrNewline($position, $remaining);
            }

            if ($char !== "\r" && $char !== "\n") {
                continue;
            }

            if ($char === "\r" && $remaining > 0 && ($raw[$position] ?? "\0") === "\n") {
                $char = $raw[$position++];
                --$remaining;
            }

            if ($part !== null) {
                $part->mimeSize = max(0, $before - $part->mimeOffset);
            }
            $part = new self($raw);
            if ($digest) {
                $part->type = 'MESSAGE';
            }
            $part->mimeOffset = $position;
            $this->parts[] = $part;
        }

        if ($part !== null) {
            $part->mimeSize = max(0, $remaining) + max(0, $position - $part->mimeOffset);
        }

        if ($this->parts === []) {
            $this->parts[] = new self($raw);
        }

        foreach ($this->parts as $part) {
            $part->parsePart($depth);
        }
    }

    /**
     * One part of a multipart: its Content-* fields, then its contents.
     * A part with nothing in it at all keeps its defaults.
     */
    private function parsePart(int $depth): void
    {
        $raw = $this->raw;
        $remaining = $this->mimeSize;

        if ($remaining === 0) {
            $this->subtype = self::defaultSubtype($this->type);
            if ($this->type === 'TEXT' && $this->parameters === []) {
                $this->parameters = [['CHARSET', $this->encoding === '7BIT' ? 'US-ASCII' : 'X-UNKNOWN']];
            }

            return;
        }

        $position = $this->mimeOffset;

        while ($remaining > 0 && ($raw[$position] ?? "\0") !== "\r" && ($raw[$position] ?? "\0") !== "\n") {
            $line = '';
            $char = ' ';

            while ($char !== "\0") {
                $next = $raw[$position++] ?? "\0";

                switch ($next) {
                    case "\r":
                        if ($remaining > 0 && ($raw[$position] ?? "\0") === "\n") {
                            ++$position;
                            --$remaining;
                        }
                        // no break
                    case "\n":
                        $following = $raw[$position] ?? "\0";
                        if ($remaining === 0 || ($following !== ' ' && $following !== "\t")) {
                            $char = "\0";
                        }
                        break;
                    case "\t":
                    case ' ':
                        if ($char !== ' ') {
                            $line .= ' ';
                            $char = ' ';
                        }
                        break;
                    default:
                        $line .= $next;
                        $char = $next;
                        break;
                }

                if ($remaining === 0 || --$remaining === 0) {
                    $char = "\0";
                }
            }

            $line = explode("\0", $line, 2)[0];

            if (strncasecmp($line, 'CONTENT-', 8) === 0 && ($colon = strpos($line, ':', 8)) !== false) {
                $this->parseContentHeader(
                    strtoupper(substr($line, 8, $colon - 8)),
                    ltrim(substr($line, $colon + 1), ' '),
                );
            }
        }

        if ($remaining > 0 && ($raw[$position] ?? "\0") === "\r") {
            --$remaining;
            ++$position;
        }
        if ($remaining > 0 && ($raw[$position] ?? "\0") === "\n") {
            --$remaining;
            ++$position;
        }

        $this->mimeSize -= $remaining;
        $this->parseContent($position, $position + $remaining, $depth + 1);
    }

    /**
     * rfc822_parse_content_header(): the one Content-* field, its name
     * already upper-cased and stripped of "CONTENT-". The first of each
     * field wins, except Content-Transfer-Encoding, where the last does.
     */
    private function parseContentHeader(string $name, string $value): void
    {
        $leading = new Rfc822Cursor($value);
        $leading->skipWhitespaceAndComments();
        $value = $leading->rest();
        $name = explode(' ', $name, 2)[0];

        switch ($name) {
            case 'ID':
                $this->id ??= $value;
                break;
            case 'DESCRIPTION':
                $this->description ??= $value;
                break;
            case 'DISPOSITION':
                if ($this->disposition !== null) {
                    break;
                }
                $cursor = new Rfc822Cursor($value);
                $end = $cursor->readWord(self::TSPECIALS);
                if ($end === null) {
                    break;
                }
                $this->disposition = strtoupper(substr($value, 0, $end));
                $cursor->skipWhitespaceAndComments();
                $this->dispositionParameters = self::parseParameters($cursor);
                break;
            case 'TYPE':
                if ($this->subtype !== null || $this->parameters !== []) {
                    break;
                }
                $this->parseContentType($value);
                break;
            case 'TRANSFER-ENCODING':
                $cursor = new Rfc822Cursor($value);
                $end = $cursor->readWord(self::TSPECIALS);
                if ($end === null) {
                    break;
                }
                $this->encoding = self::known(Rfc822Cursor::unquote(substr($value, 0, $end)), self::ENCODINGS);
                break;
        }
    }

    private function parseContentType(string $value): void
    {
        $cursor = new Rfc822Cursor($value);
        $end = $cursor->readWord(self::TSPECIALS);
        if ($end === null) {
            return;
        }

        $this->type = self::known(Rfc822Cursor::unquote(substr($value, 0, $end)), self::TYPES);
        $cursor->skipWhitespaceAndComments();

        if ($cursor->peek() === '/') {
            $cursor->skip();
            $cursor->skipWhitespaceAndComments();
            $start = $cursor->position();
            $end = $cursor->readWord(self::TSPECIALS);

            if ($end !== null) {
                $this->subtype = strtoupper(Rfc822Cursor::unquote($cursor->slice($start, $end)));
                $cursor->skipWhitespaceAndComments();
            }
        }

        $this->parameters = self::parseParameters($cursor);
    }

    /**
     * rfc822_parse_parameter(): `; attribute=value` pairs up to where they
     * stop making sense, with what was left over logged.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function parseParameters(Rfc822Cursor $cursor): array
    {
        $parameters = [];
        $lost = false;

        while (!$cursor->atEnd() && $cursor->peek() === ';') {
            $cursor->skip();
            $cursor->skipWhitespaceAndComments();
            $start = $cursor->position();
            $end = $cursor->readWord(self::TSPECIALS);

            if ($end === null) {
                $lost = true;
                break;
            }

            $attribute = strtoupper($cursor->slice($start, $end));
            $value = null;
            $cursor->skipWhitespaceAndComments();

            if ($cursor->peek() === '=') {
                $cursor->skip();
                $cursor->skipWhitespaceAndComments();
                $start = $cursor->position();
                $end = $cursor->readWord(self::TSPECIALS);

                if ($end === null) {
                    $lost = true;
                } else {
                    $value = Rfc822Cursor::unquote($cursor->slice($start, $end));
                    $cursor->skipWhitespaceAndComments();
                }
            }

            if ($value === null) {
                $value = 'MISSING_PARAMETER_VALUE';
                ErrorStack::push(sprintf('Missing parameter value: %.80s', $attribute));
            }

            $parameters[] = [$attribute, $value];

            if ($lost) {
                break;
            }
        }

        if ($lost) {
            ErrorStack::push('Missing parameter');
        } elseif (!$cursor->atEnd()) {
            ErrorStack::push(sprintf('Unexpected characters at end of parameters: %.80s', $cursor->rest()));
        }

        return $parameters;
    }

    /**
     * The table's own spelling of a name it holds, compared without regard
     * to case; a name it lacks, upper-cased.
     *
     * @param list<string> $table
     */
    private static function known(string $name, array $table): string
    {
        foreach ($table as $entry) {
            if (strcasecmp($entry, $name) === 0) {
                return $entry;
            }
        }

        return strtoupper($name);
    }

    /**
     * The next byte, or $instead where the body has none left: c-client's
     * `(i && i--) ? SNX (bs) : '\012'`.
     */
    private function nextOrNewline(int &$position, int &$remaining, string $instead = "\n"): string
    {
        if ($remaining <= 0) {
            return $instead;
        }

        --$remaining;

        return $this->raw[$position++] ?? "\0";
    }

    private static function defaultSubtype(string $type): string
    {
        return match ($type) {
            'TEXT' => 'PLAIN',
            'MULTIPART' => 'MIXED',
            'MESSAGE' => 'RFC822',
            'APPLICATION' => 'OCTET-STREAM',
            'AUDIO' => 'BASIC',
            default => 'UNKNOWN',
        };
    }
}
