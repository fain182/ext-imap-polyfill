<?php

namespace ImapPolyfill\Connection\Pop3;

/**
 * Builds the same positional BODYSTRUCTURE array shape ImapSexpParser
 * produces for a raw IMAP FETCH response, by parsing MIME headers directly —
 * POP3 has no BODYSTRUCTURE wire item, so this is done client-side, the way
 * c-client's own generic MIME parser does for any driver lacking it.
 *
 * @see \ImapPolyfill\Message\BodyStructure
 */
final class Pop3MimeStructure
{
    /**
     * @return array<int, mixed>
     */
    public static function parse(string $rawMessage): array
    {
        [$headerBlock, $body] = self::splitHeaderBody($rawMessage);
        $headers = self::parseHeaderParams($headerBlock);

        return self::parsePart($headers, $body);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitHeaderBody(string $raw): array
    {
        $pos = preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;

        if ($pos === null) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $pos), substr($raw, $pos + strlen($m[0][0]))];
    }

    /**
     * @param array<string, string> $headers
     * @return array<int, mixed>
     */
    private static function parsePart(array $headers, string $body): array
    {
        [$type, $subtype, $params] = self::contentType($headers);
        $boundary = $params['boundary'] ?? null;

        if ($type === 'multipart' && $boundary !== null) {
            return self::parseMultipart($subtype, $params, $body, $boundary);
        }

        return self::parseSinglePart($type, $subtype, $params, $headers, $body);
    }

    /**
     * @param array<string, string> $params
     * @return array<int, mixed>
     */
    private static function parseMultipart(string $subtype, array $params, string $body, string $boundary): array
    {
        $segments = preg_split('/--'.preg_quote($boundary, '/').'(--)?\r?\n?/', $body) ?: [];
        // First segment is the multipart preamble, last is the epilogue after the closing boundary.
        $segments = array_slice($segments, 1, -1);

        $parts = [];
        foreach ($segments as $segment) {
            $segment = trim($segment, "\r\n");
            if ($segment === '') {
                continue;
            }

            [$partHeaderBlock, $partBody] = self::splitHeaderBody($segment);
            $parts[] = self::parsePart(self::parseHeaderParams($partHeaderBlock), $partBody);
        }

        $paramPairs = self::flattenParams($params);

        return [...$parts, $subtype, $paramPairs, null];
    }

    /**
     * @param array<string, string> $params
     * @param array<string, string> $headers
     * @return array<int, mixed>
     */
    private static function parseSinglePart(string $type, string $subtype, array $params, array $headers, string $body): array
    {
        $encoding = strtolower($headers['content-transfer-encoding'] ?? '7bit');
        $paramPairs = self::flattenParams($params);
        $id = $headers['content-id'] ?? null;
        $description = $headers['content-description'] ?? null;
        $bytes = strlen($body);

        $result = [$type, strtoupper($subtype), $paramPairs, $id, $description, $encoding, $bytes];

        if ($type === 'text') {
            $result[] = $body === '' ? 0 : substr_count($body, "\n") + 1;
        }

        return $result;
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: string, 1: string, 2: array<string, string>}
     */
    private static function contentType(array $headers): array
    {
        $raw = $headers['content-type'] ?? 'text/plain';
        [$typeSubtype, $params] = self::splitParamized($raw);
        $parts = explode('/', $typeSubtype, 2);

        return [strtolower(trim($parts[0])), strtolower(trim($parts[1] ?? 'plain')), $params];
    }

    /**
     * Splits a "value; name=x; name2=\"y\"" header value into the leading
     * value and a lowercase-keyed parameter map.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function splitParamized(string $value): array
    {
        $segments = explode(';', $value);
        $head = trim(array_shift($segments));

        $params = [];
        foreach ($segments as $segment) {
            if (!str_contains($segment, '=')) {
                continue;
            }

            [$name, $val] = explode('=', $segment, 2);
            $params[strtolower(trim($name))] = trim(trim($val), '"');
        }

        return [$head, $params];
    }

    /**
     * @return array<string, string>
     */
    private static function parseHeaderParams(string $headerBlock): array
    {
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $headerBlock);
        $lines = preg_split('/\r\n|\n/', (string) $unfolded) ?: [];

        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }

        return $headers;
    }

    /**
     * @param array<string, string> $params
     * @return string[]
     */
    private static function flattenParams(array $params): array
    {
        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = strtoupper($name);
            $pairs[] = $value;
        }

        return $pairs;
    }
}
