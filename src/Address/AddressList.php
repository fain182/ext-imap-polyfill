<?php

namespace ImapPolyfill\Address;

use ImapPolyfill\Support\ErrorStack;

/**
 * RFC 822 address lists, including the group syntax c-client's
 * rfc822_parse_adrlist() supports and its quirks around it: a group opens
 * with a name-only entry and closes with an empty one, an unterminated
 * group is closed implicitly at end of input, and anything following a
 * closed group — a second group included — is refused rather than parsed.
 */
final class AddressList
{
    /**
     * @param Address[] $addresses
     */
    private function __construct(private readonly array $addresses)
    {
    }

    public static function parse(string $addresses, string $defaultHostname): self
    {
        // rfc822_parse_adrlist() skips whitespace before it looks at
        // anything, and comments are whitespace: a list holding nothing but
        // a comment is an empty list, not a malformed one. An unterminated
        // comment says so here, on the way past.
        $leading = new Rfc822Cursor($addresses);
        $leading->skipWhitespaceAndComments();

        if ($leading->atEnd()) {
            return new self([]);
        }

        $result = [];
        $inGroup = false;
        $groupClosed = false;

        foreach (self::tokenize($addresses) as [$part, $delimiter]) {
            $part = trim($part);

            // Everything after a group has closed is refused, which is how
            // c-client treats a second group in the same list.
            if ($groupClosed && ($part !== '' || $delimiter === ';')) {
                $result[] = Address::syntaxError('UNEXPECTED_DATA_AFTER_ADDRESS');

                return new self($result);
            }

            if (!$inGroup && $part !== '' && ($name = self::groupNameOf($part)) !== null) {
                $result[] = Address::groupStart($name);
                $inGroup = true;
                $part = trim(substr($part, strlen($name) + 1));
            }

            if ($part !== '') {
                $trailingData = null;
                $address = Address::parse($part, $defaultHostname, $trailingData);

                if ($address === null) {
                    return self::invalid($addresses, $result);
                }

                $result[] = $address;

                // What follows a complete address is neither part of it nor
                // a new one ("a@b@c.com"): c-client keeps what it read and
                // marks the rest, the same way it marks a second group.
                if ($trailingData !== null) {
                    // c-client picks its wording off the first character it
                    // could not use: a letter or a digit there reads as an
                    // address someone forgot to separate, anything else as
                    // debris.
                    $complaint = ctype_alnum($trailingData[0])
                        ? 'Must use comma to separate addresses: '
                        : 'Unexpected characters at end of address: ';

                    ErrorStack::push($complaint.substr($trailingData, 0, 80));
                    $result[] = Address::syntaxError('UNEXPECTED_DATA_AFTER_ADDRESS');

                    return new self($result);
                }
            }

            if ($delimiter === ';') {
                // A group terminator with no group open is a malformed list.
                if (!$inGroup) {
                    return self::invalid($addresses, $result);
                }

                $result[] = Address::groupEnd();
                $inGroup = false;
                $groupClosed = true;
            }
        }

        // An unterminated group still gets its closing entry.
        if ($inGroup) {
            $result[] = Address::groupEnd();
        }

        return new self($result);
    }

    /**
     * The list was malformed: c-client keeps whatever it had parsed before
     * the bad entry, appends the marker and logs — which is why this reaches
     * the global error stack from a value object, as php_imap.c's parser does.
     *
     * @param Address[] $parsed
     */
    private static function invalid(string $addresses, array $parsed): self
    {
        ErrorStack::push('Invalid mailbox list: '.$addresses);
        $parsed[] = Address::syntaxError('INVALID_ADDRESS');

        return new self($parsed);
    }

    /**
     * The group name a part opens with, if any: a colon outside quotes and
     * angle brackets, with no "@" before it (that would be a route, which
     * c-client rejects outright rather than parsing).
     */
    private static function groupNameOf(string $part): ?string
    {
        if (!preg_match('/^(?P<name>[^"<>@:]+):/', $part, $matches)) {
            return null;
        }

        return trim($matches['name']);
    }

    /**
     * Splits on both list separators, keeping which one ended each piece:
     * "," merely separates addresses, while ";" also closes a group, and a
     * group can close without any comma in sight ("A: x@e.com; z@e.com").
     * Quoted strings and angle brackets hide both.
     *
     * @return array<int, array{0: string, 1: string}> [text, delimiter]
     */
    private static function tokenize(string $addresses): array
    {
        $tokens = [];
        $current = '';
        $inQuotes = false;
        $inAngles = false;
        $length = strlen($addresses);

        for ($index = 0; $index < $length; ++$index) {
            $char = $addresses[$index];

            // Inside a quoted string a backslash escapes what follows, so an
            // escaped quote does not close the string and the delimiters it
            // hides stay hidden. Unescaping is Address::parse's job.
            if ($inQuotes && $char === '\\' && $index + 1 < $length) {
                $current .= $char.$addresses[++$index];

                continue;
            }

            if ($char === '"') {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $char === '<') {
                $inAngles = true;
            } elseif (!$inQuotes && $char === '>') {
                $inAngles = false;
            } elseif (!$inQuotes && !$inAngles && ($char === ',' || $char === ';')) {
                $tokens[] = [$current, $char];
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $tokens[] = [$current, ''];
        }

        return $tokens;
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
