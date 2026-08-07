<?php

namespace ImapPolyfill\Address;

/**
 * One entry of a parsed address list. Every field is optional because
 * c-client's ADDRESS carries more than mailboxes: a group opens with an
 * entry holding only the group name, closes with an entry holding nothing
 * at all, and a malformed list ends in a marker whose host is the literal
 * ".SYNTAX-ERROR.".
 */
final class Address
{
    private function __construct(
        public readonly ?string $mailbox,
        public readonly ?string $host,
        public readonly ?string $personal,
    ) {
    }

    /**
     * One address, scanned the way c-client's rfc822_parse_mailbox() scans
     * it: a phrase, and then either the angle brackets that make the phrase
     * a personal name, or an "@" that makes it the local part, or nothing
     * at all — in which case the phrase was the whole mailbox.
     */
    public static function parse(string $part, string $defaultHostname, bool &$trailingData = false): ?self
    {
        $cursor = new Rfc822Cursor($part);
        $cursor->skipWhitespaceAndComments();
        $personal = null;

        // A comment after the address stands in as the name only where the
        // address was written bare; c-client reaches that path from the
        // addr-spec branch, never from the one that read angle brackets.
        $angleAddress = $cursor->peek() === '<';

        if ($angleAddress) {
            $cursor->skip();
            $address = self::parseAddrSpec($cursor, $defaultHostname);
        } else {
            $start = $cursor->position();
            $phraseEnd = self::readPhrase($cursor);

            if ($phraseEnd === null) {
                return null;
            }

            // The phrase is the source text from its first word to its
            // last, so a comment *between* two words is part of it, while
            // one before or after was skipped as whitespace and is gone.
            $phrase = Rfc822Cursor::unquote($cursor->slice($start, $phraseEnd));

            if ($cursor->peek() === '<') {
                $cursor->skip();
                $angleAddress = true;
                $personal = $phrase;
                $address = self::parseAddrSpec($cursor, $defaultHostname);
            } elseif ($cursor->peek() === '@') {
                $cursor->skip();
                $host = self::readDotAtom($cursor);
                $address = [$phrase, $host !== '' ? $host : $defaultHostname];
            } else {
                $address = [$phrase, $defaultHostname];
            }
        }

        if ($address === null) {
            return null;
        }

        if ($cursor->peek() === '>') {
            $cursor->skip();
        }

        $cursor->skipWhitespaceAndComments();

        // "joe@example.com (Joe Doe)" — RFC 822's other way of writing a name.
        if (!$angleAddress) {
            $personal ??= $cursor->lastComment();
        }

        $trailingData = !$cursor->atEnd();

        return new self($address[0], $address[1], $personal);
    }

    /**
     * Words, and the dots and comments between them, up to whatever ends
     * the phrase. Answers where the last word ended — which is where the
     * phrase ends, never at the trailing whitespace or comment the scan had
     * to look through to find that out.
     */
    private static function readPhrase(Rfc822Cursor $cursor): ?int
    {
        $end = $cursor->readWord();

        if ($end === null) {
            return null;
        }

        while (true) {
            $cursor->skipWhitespaceAndComments();
            $char = $cursor->peek();

            if ($char === '.') {
                $cursor->skip();
                $end = $cursor->position();

                continue;
            }

            if ($char === null || $char === '<' || $char === '@' || $char === '>') {
                return $end;
            }

            $next = $cursor->readWord();

            if ($next === null) {
                return $end;
            }

            $end = $next;
        }
    }

    /**
     * The local@host inside a pair of angle brackets.
     *
     * @return array{0: string, 1: string}|null [mailbox, host]
     */
    private static function parseAddrSpec(Rfc822Cursor $cursor, string $defaultHostname): ?array
    {
        $cursor->skipWhitespaceAndComments();
        $start = $cursor->position();
        $localEnd = self::readPhrase($cursor);

        if ($localEnd === null) {
            return null;
        }

        $mailbox = Rfc822Cursor::unquote($cursor->slice($start, $localEnd));

        if ($cursor->peek() !== '@') {
            return [$mailbox, $defaultHostname];
        }

        $cursor->skip();
        $host = self::readDotAtom($cursor);

        return [$mailbox, $host !== '' ? $host : $defaultHostname];
    }

    /** A domain: atoms joined by dots, with comments allowed between them. */
    private static function readDotAtom(Rfc822Cursor $cursor): string
    {
        $cursor->skipWhitespaceAndComments();
        $start = $cursor->position();
        $end = self::readPhrase($cursor);

        return $end === null ? '' : Rfc822Cursor::unquote($cursor->slice($start, $end));
    }

    /** The entry c-client emits where a group begins: its name, and nothing else. */
    public static function groupStart(string $name): self
    {
        return new self($name, null, null);
    }

    /** And where it ends: an entry with no fields at all. */
    public static function groupEnd(): self
    {
        return new self(null, null, null);
    }

    /**
     * c-client reports a malformed list in-band, as an address whose host is
     * ".SYNTAX-ERROR." and whose mailbox says what went wrong.
     */
    public static function syntaxError(string $reason): self
    {
        return new self($reason, '.SYNTAX-ERROR.', null);
    }

    public function isGroupMarker(): bool
    {
        return $this->host === null;
    }

    /**
     * Only the fields c-client actually set, in the order php_imap.c adds
     * them — absent rather than null, which is observable through
     * property_exists() and var_dump().
     */
    public function toLegacyObject(): \stdClass
    {
        $address = new \stdClass();

        if ($this->mailbox !== null) {
            $address->mailbox = $this->mailbox;
        }

        if ($this->host !== null) {
            $address->host = $this->host;
        }

        if ($this->personal !== null) {
            $address->personal = $this->personal;
        }

        return $address;
    }

    /**
     * Formats as "Personal <mailbox@host>", matching ext-imap's overview shape.
     */
    public function format(): string
    {
        $mailAtHost = $this->host !== null ? "{$this->mailbox}@{$this->host}" : (string) $this->mailbox;

        return $this->personal !== null ? "{$this->personal} <{$mailAtHost}>" : $mailAtHost;
    }
}
