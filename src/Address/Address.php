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
     * rfc822_parse_mailbox(): a phrase, and then either the angle brackets
     * that make the phrase a personal name, or an "@" that makes it the
     * local part, or nothing at all — in which case the phrase was the whole
     * mailbox.
     *
     * Answers a list rather than one address because an unterminated
     * route-address produces two: what was read, and the marker saying the
     * ">" never came.
     *
     * @return Address[]|null
     */
    public static function parseMailbox(Rfc822Cursor $cursor, string $defaultHostname): ?array
    {
        $cursor->skipWhitespaceAndComments();

        if ($cursor->atEnd()) {
            return null;
        }

        // Where the parse pointer stays if nothing here is a mailbox: only a
        // route-address or an addr-spec that parsed moves it, so the caller
        // can tell "no address here" from "the list ends here".
        $start = $cursor->position();

        // A route-address with no phrase in front of it.
        if ($cursor->peek() === '<') {
            $addresses = self::parseRouteAddress($cursor, $defaultHostname);

            if ($addresses === null) {
                $cursor->seek($start);
            }

            return $addresses;
        }

        if ($cursor->readPhrase() === null) {
            $cursor->seek($start);

            return null;
        }

        $phraseEnd = $cursor->position();
        $addresses = self::parseRouteAddress($cursor, $defaultHostname);

        if ($addresses !== null) {
            // The phrase is the source text from its first word to its last,
            // so a comment *between* two words is part of it, while one
            // before or after was skipped as whitespace and is gone. It
            // replaces any name the route-address found in a comment.
            $addresses[0] = $addresses[0]->withPersonal(Rfc822Cursor::unquote($cursor->slice($start, $phraseEnd)));

            return $addresses;
        }

        // A phrase the route-address behind it will not back up is no name
        // at all. The parse starts over from the beginning of the string as
        // a plain addr-spec — which reads one word where the phrase read
        // several, and leaves the rest for the caller to complain about.
        //
        // The text it starts over on is whatever the first pass left: an
        // unterminated comment it met has already been cut off the buffer,
        // which is how c-client keeps the second pass from reporting the
        // same comment again.
        $cursor->seek($start);
        $address = self::parseAddrSpec($cursor, $defaultHostname);

        if ($address === null) {
            $cursor->seek($start);

            return null;
        }

        return [$address];
    }

    /**
     * rfc822_parse_routeaddr(): the "<...>" form, and the source route that
     * may precede the address inside it.
     *
     * @return Address[]|null
     */
    private static function parseRouteAddress(Rfc822Cursor $cursor, string $defaultHostname): ?array
    {
        $cursor->skipWhitespaceAndComments();

        if ($cursor->peek() !== '<') {
            return null;
        }

        $cursor->skip();
        $afterBracket = $cursor->position();
        $adl = self::readRoute($cursor);

        if ($adl === null) {
            $cursor->seek($afterBracket);
        }

        $address = self::parseAddrSpec($cursor, $defaultHostname);

        if ($address === null) {
            return null;
        }

        if ($adl !== null) {
            $address = $address->withAdl($adl);
        }

        // Only a live parse pointer can be looking at the closing bracket:
        // an address that ran to the end of the string never got one.
        if (!$cursor->isCancelled() && $cursor->peek() === '>') {
            $cursor->skip();
            $cursor->skipWhitespaceAndComments();

            return [$address];
        }

        $host = (string) $address->host;
        ErrorStack::push(sprintf(
            'Unterminated mailbox: %.80s@%.80s',
            (string) $address->mailbox,
            str_starts_with($host, '@') ? '<null>' : $host,
        ));

        return [$address, self::syntaxError('MISSING_MAILBOX_TERMINATOR')];
    }

    /**
     * rfc822_parse_addrspec(): the local part, the "@" and the domain, any
     * of which may be missing — a bare word is a mailbox at the default
     * host, and a domain that will not parse leaves the error host behind
     * rather than failing the address.
     */
    private static function parseAddrSpec(Rfc822Cursor $cursor, string $defaultHostname): ?self
    {
        $cursor->skipWhitespaceAndComments();

        if ($cursor->atEnd()) {
            return null;
        }

        $start = $cursor->position();
        $end = $cursor->readWord();

        if ($end === null) {
            return null;
        }

        $mailbox = Rfc822Cursor::unquote($cursor->slice($start, $end));

        // Where the mailbox ended, which is where the parse goes back to if
        // no "@" follows: the whitespace after it was only sniffed through.
        $mailboxEnd = $end;
        $cursor->skipWhitespaceAndComments();

        // "some cretin taking RFC 822 too seriously": a local part written
        // as dot-separated words, with whatever whitespace was put around
        // the dots dropped.
        while ($cursor->peek() === '.') {
            $cursor->skip();
            $cursor->skipWhitespaceAndComments();
            $wordStart = $cursor->position();
            $wordEnd = $cursor->readWord();

            if ($wordEnd === null) {
                ErrorStack::push('Invalid mailbox part after .');

                break;
            }

            $mailboxEnd = $wordEnd;
            $mailbox .= '.'.Rfc822Cursor::unquote($cursor->slice($wordStart, $wordEnd));
            $cursor->skipWhitespaceAndComments();
        }

        $host = null;

        if ($cursor->peek() === '@') {
            $cursor->skip();
            $host = self::parseDomain($cursor) ?? self::ERROR_HOST;
        } else {
            $cursor->seek($mailboxEnd);
        }

        $personal = null;

        // "joe@example.com (Joe Doe)" — RFC 822's other way of writing a
        // name. Only blanks may stand between the two, and an empty comment
        // is not a name: c-client asks for strlen() before it takes one.
        if (!$cursor->isCancelled()) {
            while ($cursor->peek() === ' ') {
                $cursor->skip();
            }

            if ($cursor->peek() === '(') {
                $comment = $cursor->skipComment(true);

                if ($comment !== null && $comment !== '') {
                    $personal = Rfc822Cursor::unquote($comment);
                }
            }

            $cursor->skipWhitespaceAndComments();
        }

        return new self($mailbox, $host ?? $defaultHostname, $personal);
    }

    /**
     * rfc822_parse_domain(): a word, the dot-separated words after it, or a
     * domain literal — "[1.2.3.4]", brackets kept, since they are what says
     * the text between them is not to be looked up anywhere.
     */
    private static function parseDomain(Rfc822Cursor $cursor): ?string
    {
        $entry = $cursor->position();
        $cursor->skipWhitespaceAndComments();

        if ($cursor->peek() === '[') {
            return self::parseDomainLiteral($cursor);
        }

        $start = $cursor->position();
        $end = $cursor->readWord();

        if ($end === null) {
            ErrorStack::push('Missing or invalid host name after @');
            // rfc822_parse_domain() leaves the caller's pointer alone when
            // it finds no domain, so a comment this looked through is still
            // there to be read as a personal name.
            $cursor->seek($entry);

            return null;
        }

        $domain = Rfc822Cursor::unquote($cursor->slice($start, $end));
        $domainEnd = $end;
        $cursor->skipWhitespaceAndComments();

        while ($cursor->peek() === '.') {
            $cursor->skip();
            $cursor->skipWhitespaceAndComments();
            $next = self::parseDomain($cursor);

            if ($next === null) {
                ErrorStack::push('Invalid domain part after .');

                break;
            }

            // Unquoted a second time, as c-client's rfc822_cpy() of the
            // recursive call's answer does.
            $domain .= '.'.Rfc822Cursor::unquote($next);
            $domainEnd = $cursor->position();
            $cursor->skipWhitespaceAndComments();
        }

        $cursor->seek($domainEnd);

        return $domain;
    }

    /** The "[...]" form, read as a word delimited by the bracket and the backslash. */
    private static function parseDomainLiteral(Rfc822Cursor $cursor): ?string
    {
        $start = $cursor->position();
        $cursor->skip();

        if ($cursor->readWord(Rfc822Cursor::LITERAL_SPECIALS) === null) {
            ErrorStack::push('Empty domain literal');
            // The parse pointer is left NIL here rather than after the
            // brackets, so nothing that follows is read at all.
            $cursor->cancel();

            return null;
        }

        if ($cursor->peek() !== ']') {
            ErrorStack::push('Unterminated domain literal');

            return null;
        }

        $cursor->skip();

        return $cursor->slice($start, $cursor->position());
    }

    /**
     * The A-D-L of a route-addr: "@domain" repeated, comma-separated, ended
     * by the colon that introduces the address itself. Answers null where
     * there is no route, which is every address written this century.
     */
    private static function readRoute(Rfc822Cursor $cursor): ?string
    {
        $cursor->skipWhitespaceAndComments();
        $route = null;

        while ($cursor->peek() === '@') {
            $cursor->skip();
            $domain = self::parseDomain($cursor);

            if ($domain === null) {
                break;
            }

            $route = $route === null ? '@'.$domain : $route.',@'.$domain;
            $cursor->skipWhitespaceAndComments();

            if ($cursor->peek() !== ',') {
                break;
            }

            $cursor->skip();
            $cursor->skipWhitespaceAndComments();
        }

        if ($route === null) {
            return null;
        }

        if ($cursor->peek() !== ':') {
            ErrorStack::push('Unterminated at-domain-list: '.substr($route, 0, 80).substr($cursor->rest(), 0, 80));

            return null;
        }

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

    private function withPersonal(string $personal): self
    {
        return new self($this->mailbox, $this->host, $personal, $this->adl);
    }

    private function withAdl(string $adl): self
    {
        return new self($this->mailbox, $this->host, $this->personal, $adl);
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

    /** Formats as "Personal <mailbox@host>", matching ext-imap's overview shape. */
    public function format(): string
    {
        $mailAtHost = $this->host !== null ? "{$this->mailbox}@{$this->host}" : (string) $this->mailbox;

        return $this->personal !== null ? "{$this->personal} <{$mailAtHost}>" : $mailAtHost;
    }
}
