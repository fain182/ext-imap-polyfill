<?php

namespace ImapPolyfill\Tests\Support;

/**
 * Renders a generated fixture as PHP source.
 *
 * var_export() would be enough but for the payloads: a decoded ISO-8859-1
 * string or a raw 8-bit body written verbatim leaves a checked-in file
 * that is not valid UTF-8, which no editor or diff renders honestly.
 * Everything outside printable ASCII is escaped, so a fixture stays
 * readable as the specification it is.
 */
final class FixtureExport
{
    /**
     * A value in the shape a matrix fixture records it: what kind of
     * answer came back, not the answer itself. A header string carries the
     * server's own wording and a folder's generated name, neither of which
     * is what such a fixture is comparing.
     */
    public static function shape(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => 'int:'.$value,
            is_string($value) => $value === '' ? 'string:empty' : 'string:'.strlen($value).' bytes',
            is_array($value) => 'array:'.count($value),
            is_object($value) => 'object:'.$value::class,
            default => get_debug_type($value),
        };
    }

    public static function render(mixed $value, int $depth = 0): string
    {
        $pad = str_repeat('    ', $depth + 1);

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $lines = [];
            foreach ($value as $key => $item) {
                $lines[] = $pad.self::render($key, $depth + 1).' => '.self::render($item, $depth + 1).',';
            }

            return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
        }

        if (is_string($value)) {
            $escaped = preg_replace_callback(
                '/[^\x20-\x7E]|["$\\\\]/',
                static fn (array $m): string => sprintf('\x%02X', ord($m[0])),
                $value
            );

            return '"'.$escaped.'"';
        }

        return var_export($value, true);
    }
}
