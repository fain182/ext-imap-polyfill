<?php

namespace ImapPolyfill;

final class RawHeaderFields
{
    /**
     * Unfolds continuation lines and splits a raw RFC822 header block into a
     * lowercase-keyed map of header name => value.
     *
     * @return array<string, string>
     */
    public static function parse(string $rawHeader): array
    {
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $rawHeader);
        $lines = preg_split('/\r\n|\n/', (string) $unfolded);

        $fields = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $fields[$name] = $value;
        }

        return $fields;
    }
}
