<?php

namespace ImapPolyfill\Address;

use ImapPolyfill\Support\ErrorStack;

/**
 * RFC 822 address lists, parsed the way c-client's rfc822_parse_adrlist()
 * parses them: one pass over the text, an address at a time, with the group
 * syntax and its quirks — a group opens with a name-only entry and closes
 * with an empty one, an unterminated group is closed implicitly at the end
 * of the input, and a complaint inside a group reads differently from the
 * same complaint outside one.
 *
 * The single pass is the point. Splitting on commas first and parsing the
 * pieces afterwards gets the well-formed lists right and every malformed one
 * subtly wrong: a comma inside a group is not the same separator, the text a
 * complaint quotes is what remained of the *whole* list rather than of one
 * piece, and where the parse gives up decides how much of the list survives.
 */
final class AddressList
{
    /** MAXGROUPDEPTH: "RFC [2]822 doesn't allow any group nesting" anyway. */
    private const MAX_GROUP_DEPTH = 50;

    /**
     * @param Address[] $addresses
     */
    private function __construct(private readonly array $addresses)
    {
    }

    /**
     * The same header written more than once. c-client parses each
     * occurrence on its own and appends what it read to the list it already
     * has, so a message with two From headers has one list of two addresses
     * — and each line keeps its own complaint and its own marker, which a
     * single parse of the two joined together would not.
     */
    public function append(self $other): self
    {
        return new self([...$this->addresses, ...$other->addresses]);
    }

    public static function parse(string $addresses, string $defaultHostname): self
    {
        $cursor = new Rfc822Cursor($addresses);

        // rfc822_parse_adrlist() skips whitespace before it looks at
        // anything, and comments are whitespace: a list holding nothing but
        // a comment is an empty list, not a malformed one. An unterminated
        // comment says so here, on the way past.
        $cursor->skipWhitespaceAndComments();

        if ($cursor->atEnd()) {
            return new self([]);
        }

        $parsed = [];

        while (!$cursor->isCancelled()) {
            // "RFC 822 allowed null addresses!!"
            while ($cursor->peek() === ',') {
                $cursor->skip();
                $cursor->skipWhitespaceAndComments();
            }

            if ($cursor->atEnd()) {
                break;
            }

            $outcome = self::parseAddress($cursor, $defaultHostname, $parsed, 0);

            if ($outcome === AddressParse::Cancelled) {
                break;
            }

            if ($outcome === AddressParse::Read) {
                $cursor->skipWhitespaceAndComments();
                $next = $cursor->peek();

                if ($next === ',') {
                    $cursor->skip();

                    continue;
                }

                if ($next === null) {
                    break;
                }

                // c-client picks its wording off the first character it
                // could not use: a letter or a digit there reads as an
                // address someone forgot to separate, anything else as
                // debris.
                ErrorStack::push(sprintf(
                    ctype_alnum($next)
                        ? 'Must use comma to separate addresses: %.80s'
                        : 'Unexpected characters at end of address: %.80s',
                    $cursor->rest(),
                ));
                $parsed[] = Address::syntaxError('UNEXPECTED_DATA_AFTER_ADDRESS');

                break;
            }

            $cursor->skipWhitespaceAndComments();
            ErrorStack::push($cursor->atEnd()
                ? 'Missing address after comma'
                : sprintf('Invalid mailbox list: %.80s', $cursor->rest()));
            $parsed[] = Address::syntaxError('INVALID_ADDRESS');

            break;
        }

        return new self($parsed);
    }

    /**
     * rfc822_parse_address(): a group if the text reads as one, otherwise a
     * mailbox, appended to the list as it is read.
     *
     * @param Address[] $parsed
     */
    private static function parseAddress(Rfc822Cursor $cursor, string $defaultHostname, array &$parsed, int $depth): AddressParse
    {
        if ($cursor->isCancelled()) {
            return AddressParse::Cancelled;
        }

        $cursor->skipWhitespaceAndComments();

        if ($cursor->atEnd()) {
            return AddressParse::NotAnAddress;
        }

        if (self::parseGroup($cursor, $defaultHostname, $parsed, $depth)) {
            return $cursor->isCancelled() ? AddressParse::Cancelled : AddressParse::Read;
        }

        $addresses = Address::parseMailbox($cursor, $defaultHostname);

        if ($addresses !== null) {
            array_push($parsed, ...$addresses);

            return $cursor->isCancelled() ? AddressParse::Cancelled : AddressParse::Read;
        }

        // A mailbox that failed while something else was cancelling the
        // parse is not itself a malformed entry: whatever cancelled it has
        // already said so.
        return $cursor->isCancelled() ? AddressParse::Cancelled : AddressParse::NotAnAddress;
    }

    /**
     * rfc822_parse_group(): a phrase, a colon, the addresses up to the
     * semicolon, and the empty entry that marks the end. Answers false where
     * the text is not a group at all, having moved nothing.
     *
     * @param Address[] $parsed
     */
    private static function parseGroup(Rfc822Cursor $cursor, string $defaultHostname, array &$parsed, int $depth): bool
    {
        if ($depth > self::MAX_GROUP_DEPTH) {
            ErrorStack::push('Ignoring excessively deep group recursion');

            return false;
        }

        $cursor->skipWhitespaceAndComments();

        if ($cursor->atEnd()) {
            return false;
        }

        $start = $cursor->position();

        // A group with no name at all is written ":addresses;", so the
        // phrase is only looked for when the colon is not already here.
        if ($cursor->peek() !== ':') {
            if ($cursor->readPhrase() === null) {
                $cursor->seek($start);

                return false;
            }

            $nameEnd = $cursor->position();
            $cursor->skipWhitespaceAndComments();

            if ($cursor->peek() !== ':') {
                $cursor->seek($start);

                return false;
            }
        } else {
            $nameEnd = $start;
        }

        $parsed[] = Address::groupStart(Rfc822Cursor::unquote($cursor->slice($start, $nameEnd)));
        $cursor->skip();
        $cursor->skipWhitespaceAndComments();

        while (!$cursor->isCancelled() && !$cursor->atEnd() && $cursor->peek() !== ';') {
            $outcome = self::parseAddress($cursor, $defaultHostname, $parsed, $depth + 1);

            if ($outcome === AddressParse::Cancelled) {
                continue;
            }

            if ($outcome === AddressParse::NotAnAddress) {
                ErrorStack::push(sprintf('Invalid group mailbox list: %.80s', $cursor->rest()));
                $cursor->cancel();
                $parsed[] = Address::syntaxError('INVALID_ADDRESS_IN_GROUP');

                continue;
            }

            $cursor->skipWhitespaceAndComments();
            $next = $cursor->peek();

            if ($next === ',') {
                $cursor->skip();
            } elseif ($next !== ';' && $next !== null) {
                ErrorStack::push(sprintf('Unexpected characters after address in group: %.80s', $cursor->rest()));
                $cursor->cancel();
                $parsed[] = Address::syntaxError('UNEXPECTED_DATA_AFTER_ADDRESS_IN_GROUP');
            }
        }

        if (!$cursor->isCancelled()) {
            if ($cursor->peek() === ';') {
                $cursor->skip();
            }

            $cursor->skipWhitespaceAndComments();
        }

        $parsed[] = Address::groupEnd();

        return true;
    }

    /**
     * @return \stdClass[]
     */
    public function toLegacyArray(): array
    {
        return array_map(static fn (Address $address) => $address->toLegacyObject(), $this->addresses);
    }

    public function first(): ?Address
    {
        return $this->addresses[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->addresses === [];
    }

    /**
     * The list written back out, c-client's rfc822_output_address_list()
     * with php_imap.c's arguments: no pretty-printing, so no folding, and
     * the personal name quoted against rspecials.
     *
     * This is where the "*address" properties of an envelope come from —
     * they are *written*, not the header text they were read from. The two
     * agree while the header is well formed and part company the moment it
     * is not: a mailbox with no host reads back with the default host in it,
     * and an address c-client could not parse reads back as the marker it
     * put in its place.
     */
    public function write(): string
    {
        $written = '';
        $depth = 0;

        foreach ($this->addresses as $index => $address) {
            $next = $this->addresses[$index + 1] ?? null;

            if ($address->host !== null) {
                // Inside a group only the group's own name is written; the
                // members are already in it.
                $written .= $address->writeWithPersonal();

                if ($next?->mailbox !== null) {
                    $written .= ', ';
                }

                continue;
            }

            if ($address->mailbox !== null) {
                $written .= Rfc822Address::quote($address->mailbox, Rfc822Address::SPECIALS).': ';
                $depth++;

                continue;
            }

            if ($depth > 0) {
                $written .= ';';

                if (--$depth === 0 && $next?->mailbox !== null) {
                    $written .= ', ';
                }
            }
        }

        return $written;
    }

    public function firstAsString(): ?string
    {
        return $this->first()?->format();
    }
}
