<?php

namespace ImapPolyfill\Address;

final class Address
{
    private function __construct(
        public readonly string $mailbox,
        public readonly string $host,
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

    public function toLegacyObject(): \stdClass
    {
        $address = new \stdClass();
        $address->mailbox = $this->mailbox;
        $address->host = $this->host;
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
        $mailAtHost = "{$this->mailbox}@{$this->host}";

        return $this->personal !== null ? "{$this->personal} <{$mailAtHost}>" : $mailAtHost;
    }
}
