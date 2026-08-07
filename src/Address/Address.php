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

    public static function parse(string $part, string $defaultHostname): ?self
    {
        if ($part === '') {
            return null;
        }

        $quoted = null;

        if (str_starts_with($part, '"')) {
            $split = self::splitQuotedName($part);

            // A quote that never closes makes the whole list malformed,
            // rather than the name running to the end of the input.
            if ($split === null) {
                return null;
            }

            [$quoted, $part] = $split;
        }

        // The name is ended by whitespace or by the bracket that opens the
        // address — nothing has to stand between the two.
        if (!preg_match(
            '/^(?:"?(?P<name>[^"<]*)"?(?:\s+|(?=<)))?<?(?P<mailbox>[^\s@<>]+)(?:@(?P<host>[^\s@<>]+))?>?$/',
            $part,
            $matches
        )) {
            return null;
        }

        $personal = trim($matches['name']);

        return new self(
            $matches['mailbox'],
            ($matches['host'] ?? '') !== '' ? $matches['host'] : $defaultHostname,
            // An empty name is no name at all — unless it was written as
            // one, where c-client keeps the empty string it copied out.
            $quoted ?? ($personal !== '' ? $personal : null),
        );
    }

    /**
     * Splits a part opening with a quoted personal name into that name and
     * whatever follows it. A quoted string is the one place an address may
     * carry the characters that otherwise delimit the list — a quote of its
     * own included, escaped — and c-client drops the backslashes as it
     * copies the name out. Null when the quote never closes.
     *
     * @return array{0: string, 1: string}|null [personal, remainder]
     */
    private static function splitQuotedName(string $part): ?array
    {
        $name = '';
        $length = strlen($part);

        for ($index = 1; $index < $length; ++$index) {
            $char = $part[$index];

            if ($char === '\\' && $index + 1 < $length) {
                $name .= $part[++$index];

                continue;
            }

            if ($char === '"') {
                return [$name, trim(substr($part, $index + 1))];
            }

            $name .= $char;
        }

        return null;
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
