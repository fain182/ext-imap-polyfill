<?php

namespace ImapPolyfill\Address;

use ImapPolyfill\Support\ErrorStack;

/**
 * One entry of a parsed address list. Every field is optional because
 * c-client's ADDRESS carries more than mailboxes: a group opens with an
 * entry holding only the group name, closes with an entry holding nothing
 * at all, and a malformed list ends in a marker whose host is the literal
 * ".SYNTAX-ERROR.".
 */
final class Address
{
    /** c-client's errhst: the host it writes where a real one is missing. */
    private const ERROR_HOST = '.SYNTAX-ERROR.';

    private function __construct(
        public readonly ?string $mailbox,
        public readonly ?string $host,
        public readonly ?string $personal,
        public readonly ?string $adl = null,
    ) {
    }

    /**
     * One address, scanned the way c-client's rfc822_parse_mailbox() scans
     * it: a phrase, and then either the angle brackets that make the phrase
     * a personal name, or an "@" that makes it the local part, or nothing
     * at all — in which case the phrase was the whole mailbox.
     */
    public static function parse(
        string $part,
        string $defaultHostname,
        ?string &$trailingData = null,
        string &$unparsed = '',
    ): ?self {
        $cursor = new Rfc822Cursor($part);
        $cursor->skipWhitespaceAndComments();

        // What the caller names in its complaint if this comes to nothing:
        // rfc822_parse_mailbox() moves the caller's pointer only when it
        // succeeds, so what is left over is the text as it stood here.
        $unparsed = $cursor->rest();
        $personal = null;

        // A comment after the address stands in as the name only where the
        // address was written bare; c-client reaches that path from the
        // addr-spec branch, never from the one that read angle brackets.
        $angleAddress = $cursor->peek() === '<';

        $adl = null;

        if ($angleAddress) {
            $cursor->skip();
            $address = self::parseAddrSpec($cursor, $defaultHostname, $adl);
        } else {
            $start = $cursor->position();
            $phraseEnd = self::readPhrase($cursor);

            if ($phraseEnd === null) {
                return null;
            }

            $address = null;

            if ($cursor->peek() === '<') {
                $cursor->skip();
                $address = self::parseAddrSpec($cursor, $defaultHostname, $adl);

                if ($address !== null) {
                    // The phrase is the source text from its first word to
                    // its last, so a comment *between* two words is part of
                    // it, while one before or after was skipped as
                    // whitespace and is gone.
                    $angleAddress = true;
                    $personal = Rfc822Cursor::unquote($cursor->slice($start, $phraseEnd));
                }
            }

            if ($address === null) {
                // rfc822_parse_mailbox(): a phrase the route-address behind it
                // will not back up is no name at all. The parse starts over
                // from the beginning of the string as a plain addr-spec —
                // which reads one word where the phrase read several, and
                // leaves the rest for the caller to complain about.
                //
                // The text it starts over on stops at any unterminated
                // comment the first pass found, which c-client arranges by
                // writing a NUL there. Without that the second pass reports
                // the same comment again.
                $nuked = $cursor->unterminatedCommentAt();
                $cursor = new Rfc822Cursor($nuked === null ? $part : substr($part, 0, $nuked));
                $adl = null;
                $address = self::parseAddrSpec($cursor, $defaultHostname, $adl);
            }
        }

        if ($address === null) {
            return null;
        }

        if ($cursor->peek() === '>') {
            $cursor->skip();
        }

        $cursor->skipWhitespaceAndComments();

        // "joe@example.com (Joe Doe)" — RFC 822's other way of writing a
        // name. An empty comment is not one: rfc822_parse_addrspec() asks
        // for strlen() before it takes the comment as a personal name.
        if (!$angleAddress && $personal === null && ($comment = $cursor->lastComment()) !== null && $comment !== '') {
            $personal = $comment;
        }

        $trailingData = $cursor->atEnd() ? null : $cursor->rest();

        return new self($address[0], $address[1], $personal, $adl);
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
     * The local@host inside a pair of angle brackets, and the source route
     * that may precede it.
     *
     * @param ?string $adl the route, as c-client's rfc822_parse_routeaddr
     *   keeps it: the text between the opening bracket and the colon,
     *   leading "@" and separating commas included
     *
     * @return array{0: string, 1: string}|null [mailbox, host]
     */
    private static function parseAddrSpec(Rfc822Cursor $cursor, string $defaultHostname, ?string &$adl = null): ?array
    {
        $cursor->skipWhitespaceAndComments();
        $adl = self::readRoute($cursor);
        $cursor->skipWhitespaceAndComments();
        $mailbox = self::readDottedWords($cursor);

        if ($mailbox === null) {
            return null;
        }

        if ($cursor->peek() !== '@') {
            return [$mailbox, $defaultHostname];
        }

        $cursor->skip();
        $cursor->skipWhitespaceAndComments();

        // A domain literal is its own branch, with its own complaints: the
        // "missing host name" one belongs to the branch that reads a word.
        if ($cursor->peek() === '[') {
            return [$mailbox, self::readDomainLiteral($cursor) ?? self::ERROR_HOST];
        }

        $host = self::readDottedWords($cursor);

        if ($host === null) {
            // rfc822_parse_domain() answers NIL and says so, and
            // rfc822_parse_addrspec() puts the error host in its place —
            // the address is kept, with the marker where its domain would be.
            ErrorStack::push('Missing or invalid host name after @');

            return [$mailbox, self::ERROR_HOST];
        }

        return [$mailbox, $host];
    }

    /**
     * A domain written as an address rather than a name — "[1.2.3.4]".
     * rfc822_parse_domain() keeps the brackets, which is why this is not
     * simply a word: they are what says the text between them is not to be
     * looked up anywhere.
     */
    private static function readDomainLiteral(Rfc822Cursor $cursor): ?string
    {
        $start = $cursor->position();
        $cursor->skip();
        $content = $cursor->position();

        while (($char = $cursor->peek()) !== null && $char !== ']') {
            $cursor->skip($char === '\\' ? 2 : 1);
        }

        // Read as a word delimited by "]" and "\\": an empty one is no word
        // at all, and so is a run that reaches the end without its bracket.
        if ($cursor->position() === $content) {
            ErrorStack::push('Empty domain literal');
            // The parse pointer is left NIL here, not after the brackets, so
            // the "]" is not data trailing the address.
            $cursor->skipToEnd();

            return null;
        }

        if ($cursor->peek() === null) {
            ErrorStack::push('Unterminated domain literal');

            return null;
        }

        $cursor->skip();

        return $cursor->slice($start, $cursor->position());
    }

    /**
     * One word, and then the dot-separated words after it — joined with
     * dots, and with whatever whitespace was written around those dots
     * dropped. A second *word*, with no dot between, is not part of it: that
     * is where the address ends and the caller's complaint about the rest
     * begins.
     *
     * Both halves of an addr-spec are read this way. rfc822_parse_addrspec()
     * and rfc822_parse_domain() are separate functions in c-client and this
     * is what they have in common; the mailbox differs only in having no
     * error host to fall back on.
     */
    private static function readDottedWords(Rfc822Cursor $cursor): ?string
    {
        $start = $cursor->position();
        $end = $cursor->readWord();

        if ($end === null) {
            return null;
        }

        $mailbox = Rfc822Cursor::unquote($cursor->slice($start, $end));

        while (true) {
            $cursor->skipWhitespaceAndComments();

            if ($cursor->peek() !== '.') {
                return $mailbox;
            }

            $cursor->skip();
            $cursor->skipWhitespaceAndComments();
            $start = $cursor->position();
            $end = $cursor->readWord();
            $mailbox .= '.';

            if ($end === null) {
                return $mailbox;
            }

            $mailbox .= Rfc822Cursor::unquote($cursor->slice($start, $end));
        }
    }

    /**
     * The A-D-L of a route-addr: "@domain" repeated, comma-separated, ended
     * by the colon that introduces the address itself. Answers null where
     * there is no route, which is every address written this century.
     */
    private static function readRoute(Rfc822Cursor $cursor): ?string
    {
        if ($cursor->peek() !== '@') {
            return null;
        }

        $start = $cursor->position();

        while ($cursor->peek() === '@') {
            $cursor->skip();
            $cursor->skipWhitespaceAndComments();
            self::readDottedWords($cursor);
            $cursor->skipWhitespaceAndComments();

            if ($cursor->peek() === ',') {
                $cursor->skip();
                $cursor->skipWhitespaceAndComments();

                continue;
            }

            break;
        }

        if ($cursor->peek() !== ':') {
            return null;
        }

        $route = $cursor->slice($start, $cursor->position());
        $cursor->skip();

        return $route;
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
        return new self($reason, self::ERROR_HOST, null);
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

        if ($this->adl !== null) {
            $address->adl = $this->adl;
        }

        return $address;
    }

    /**
     * Formats as "Personal <mailbox@host>", matching ext-imap's overview shape.
     */
    /**
     * This one address as rfc822_output_address_list() writes it: the
     * personal name in front of the angle brackets when there is one, and
     * the address alone when there is not. A source route is not written —
     * the A-D-L support in c-client is behind an #if that php_imap.c's
     * build leaves off, so an address parsed out of one reads back without
     * it.
     */
    public function writeWithPersonal(): string
    {
        return Rfc822Address::write($this->mailbox ?? '', $this->host ?? '', $this->personal ?? '');
    }

    public function format(): string
    {
        $mailAtHost = $this->host !== null ? "{$this->mailbox}@{$this->host}" : (string) $this->mailbox;

        return $this->personal !== null ? "{$this->personal} <{$mailAtHost}>" : $mailAtHost;
    }
}
