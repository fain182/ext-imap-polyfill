<?php

namespace Fain182\ImapPolyfill;

final class MessageSequence
{
    /**
     * Expands an ext-imap message sequence string ("1", "1:3", "1,3,5", "4:*")
     * into a concrete list of message numbers/UIDs.
     *
     * @return int[]
     */
    public static function expand(string $sequence, int $lastId): array
    {
        $ids = [];

        foreach (explode(',', $sequence) as $part) {
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
