<?php

namespace ImapPolyfill\Message;

final class MessageSequence
{
    private function __construct(private readonly string $sequence)
    {
    }

    public static function parse(string $sequence): self
    {
        return new self($sequence);
    }

    /**
     * Expands an ext-imap message sequence string ("1", "1:3", "1,3,5", "4:*")
     * into a concrete list of message numbers/UIDs.
     *
     * @return int[]
     */
    public function expand(int $lastId): array
    {
        $ids = [];

        foreach (explode(',', $this->sequence) as $part) {
            if (str_contains($part, ':')) {
                [$from, $to] = explode(':', $part, 2);
                $from = (int) $from;
                $to = trim($to) === '*' ? $lastId : (int) $to;

                for ($i = $from; $i <= $to; $i++) {
                    $ids[] = $i;
                }
            } else {
                $ids[] = trim($part) === '*' ? $lastId : (int) $part;
            }
        }

        return $ids;
    }
}
