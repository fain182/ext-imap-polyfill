<?php

namespace ImapPolyfill\Address;

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
        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $addresses);

        $result = [];
        foreach ($parts as $part) {
            $address = Address::parse(trim($part), $defaultHostname);
            if ($address !== null) {
                $result[] = $address;
            }
        }

        return new self($result);
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

    public function firstAsString(): ?string
    {
        return $this->first()?->format();
    }
}
