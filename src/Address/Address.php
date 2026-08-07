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

        if (!preg_match(
            '/^(?:"?(?P<name>[^"<]*)"?\s+)?<?(?P<mailbox>[^\s@<>]+)(?:@(?P<host>[^\s@<>]+))?>?$/',
            $part,
            $matches
        )) {
            return null;
        }

        $personal = trim($matches['name']);

        return new self(
            $matches['mailbox'],
            ($matches['host'] ?? '') !== '' ? $matches['host'] : $defaultHostname,
            $personal !== '' ? $personal : null,
        );
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
