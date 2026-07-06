<?php

namespace ImapPolyfill\Address;

class AddressList
{
    /**
     * @return \stdClass[]
     */
    public static function parse(string $addresses, string $defaultHostname): array
    {
        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $addresses);

        $result = [];
        foreach ($parts as $part) {
            $address = self::parseOne(trim($part), $defaultHostname);
            if ($address !== null) {
                $result[] = $address;
            }
        }

        return $result;
    }

    /**
     * Formats only the first address of a header value as a single
     * "Personal <mailbox@host>" string, matching ext-imap's overview shape.
     */
    public static function firstAsString(string $addresses, string $defaultHostname): ?string
    {
        $list = self::parse($addresses, $defaultHostname);
        if ($list === []) {
            return null;
        }

        $address = $list[0];
        $mailAtHost = "{$address->mailbox}@{$address->host}";

        return isset($address->personal) ? "{$address->personal} <{$mailAtHost}>" : $mailAtHost;
    }

    private static function parseOne(string $part, string $defaultHostname): ?\stdClass
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

        $address = new \stdClass();
        $address->mailbox = $matches['mailbox'];
        $address->host = ($matches['host'] ?? '') !== '' ? $matches['host'] : $defaultHostname;

        $personal = trim($matches['name'] ?? '');
        if ($personal !== '') {
            $address->personal = $personal;
        }

        return $address;
    }
}
