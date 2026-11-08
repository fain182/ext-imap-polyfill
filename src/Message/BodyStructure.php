<?php

namespace ImapPolyfill\Message;

final class BodyStructure
{
    private const TYPES = [
        'text' => 0,
        'multipart' => 1,
        'message' => 2,
        'application' => 3,
        'audio' => 4,
        'image' => 5,
        'video' => 6,
        'model' => 7,
    ];

    private const ENCODINGS = [
        '7bit' => 0,
        '8bit' => 1,
        'binary' => 2,
        'base64' => 3,
        'quoted-printable' => 4,
    ];

    /**
     * @param array<int, mixed> $parsed a parenthesized BODYSTRUCTURE list already
     *   decoded by ImapSexpParser (multipart if the first element is itself a list)
     */
    public static function build(array $parsed): \stdClass
    {
        return is_array($parsed[0]) ? self::buildMultipart($parsed) : self::buildSinglePart($parsed);
    }

    /**
     * Walks a dotted section path ("1", "2.1", ...) down the full parsed
     * BODYSTRUCTURE tree to find the sub-tree for imap_bodystruct(), the same
     * way c-client's mail_body() indexes into BODY.nested.part for multipart
     * envelopes and steps into the embedded message for message/rfc822 parts.
     *
     * @param array<int, mixed> $parsed
     * @return array<int, mixed>|null
     */
    public static function resolveSection(array $parsed, string $section): ?array
    {
        if ($section === '' || !preg_match('/^\d+(\.\d+)*$/', $section)) {
            return null;
        }

        return self::descend($parsed, array_map('intval', explode('.', $section)));
    }

    /**
     * @param array<int, mixed> $node
     * @param int[] $segments
     * @return array<int, mixed>|null
     */
    private static function descend(array $node, array $segments): ?array
    {
        if ($segments === []) {
            return $node;
        }

        $index = array_shift($segments);
        if ($index < 1) {
            return null;
        }

        if (is_array($node[0])) {
            $children = [];
            $i = 0;
            while (isset($node[$i]) && is_array($node[$i])) {
                $children[] = $node[$i];
                $i++;
            }

            if ($index > count($children)) {
                return null;
            }

            $node = $children[$index - 1];
        } elseif ($index !== 1) {
            // Singlepart: index 1 names the part itself; anything else is invalid.
            return null;
        }

        if ($segments === []) {
            return $node;
        }

        if (is_array($node[0])) {
            return self::descend($node, $segments);
        }

        // Only a message/rfc822 part carries a further nested body to
        // navigate into, and its parts are numbered as if the enclosed
        // message were the body part itself (RFC 3501 §6.4.5): the next
        // segment indexes the enclosed body, with no segment of its own for
        // the step inwards. Layout is [type, subtype, params, id,
        // description, encoding, size, envelope, body, ...].
        $type = strtolower((string) $node[0]);
        $subtype = strtolower((string) ($node[1] ?? ''));
        if ($type !== 'message' || $subtype !== 'rfc822' || !is_array($node[8] ?? null)) {
            return null;
        }

        return self::descend($node[8], $segments);
    }

    /**
     * @param array<int, mixed> $parsed
     */
    private static function buildMultipart(array $parsed): \stdClass
    {
        $parts = [];
        $i = 0;
        while (isset($parsed[$i]) && is_array($parsed[$i])) {
            $parts[] = self::build($parsed[$i]);
            $i++;
        }

        return self::node(
            type: self::TYPES['multipart'],
            encoding: 0, // ENC7BIT: multipart bodies carry no encoding atom of their own
            subtype: $parsed[$i] ?? null,
            description: null,
            id: null,
            lines: null,
            bytes: null,
            disposition: $parsed[$i + 2] ?? null,
            parameters: $parsed[$i + 1] ?? null,
            parts: $parts,
        );
    }

    /**
     * @param array<int, mixed> $parsed
     */
    private static function buildSinglePart(array $parsed): \stdClass
    {
        $type = self::TYPES[strtolower((string) $parsed[0])] ?? 8; // TYPEOTHER
        $subtype = $parsed[1] ?? null;
        $lines = null;
        $parts = null;

        $next = 7;
        if ($type === self::TYPES['text']) {
            $lines = $parsed[$next] ?? null;
            $next++;
        } elseif ($type === self::TYPES['message'] && strtolower((string) $subtype) === 'rfc822') {
            $next++; // skip envelope
            $parts = [self::build($parsed[$next])];
            $next++;
            $lines = $parsed[$next] ?? null;
            $next++;
        }

        $next++; // skip md5

        return self::node(
            type: $type,
            encoding: self::ENCODINGS[strtolower((string) ($parsed[5] ?? ''))] ?? 5, // ENCOTHER
            subtype: $subtype,
            description: $parsed[4] ?? null,
            id: $parsed[3] ?? null,
            lines: $lines,
            bytes: $parsed[6] ?? null,
            disposition: $parsed[$next] ?? null,
            parameters: $parsed[2] ?? null,
            parts: $parts,
        );
    }

    /**
     * The properties in the order php_imap_populate_body_struct_object()
     * sets them, with `parts` last as _php_imap_add_body() adds it: the
     * order is what var_dump(), json_encode() and a foreach over the
     * object show.
     *
     * @param \stdClass[]|null $parts
     */
    private static function node(
        int $type,
        int $encoding,
        mixed $subtype,
        mixed $description,
        mixed $id,
        mixed $lines,
        mixed $bytes,
        mixed $disposition,
        mixed $parameters,
        ?array $parts,
    ): \stdClass {
        $result = new \stdClass();
        $result->type = $type;
        $result->encoding = $encoding;

        $result->ifsubtype = $subtype !== null ? 1 : 0;
        if ($subtype !== null) {
            $result->subtype = strtoupper($subtype);
        }

        $result->ifdescription = $description !== null ? 1 : 0;
        if ($description !== null) {
            $result->description = $description;
        }

        $result->ifid = $id !== null ? 1 : 0;
        if ($id !== null) {
            $result->id = $id;
        }

        // c-client's size fields are unsigned longs php_imap.c tests for
        // non-zero: a zero count is left out, not reported as 0.
        if (!empty($lines)) {
            $result->lines = $lines;
        }
        if (!empty($bytes)) {
            $result->bytes = $bytes;
        }

        $result->ifdisposition = empty($disposition) ? 0 : 1;
        if (!empty($disposition)) {
            $result->disposition = $disposition[0];
        }

        $result->ifdparameters = empty($disposition[1]) ? 0 : 1;
        if (!empty($disposition[1])) {
            $result->dparameters = self::pairsToObjects($disposition[1]);
        }

        $result->ifparameters = empty($parameters) ? 0 : 1;
        $result->parameters = empty($parameters) ? new \stdClass() : self::pairsToObjects($parameters);

        if ($parts !== null) {
            $result->parts = $parts;
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $pairs
     * @return \stdClass[]
     */
    private static function pairsToObjects(array $pairs): array
    {
        $result = [];
        for ($i = 0; $i < count($pairs); $i += 2) {
            $pair = new \stdClass();
            $pair->attribute = $pairs[$i];
            $pair->value = $pairs[$i + 1] ?? null;
            $result[] = $pair;
        }

        return $result;
    }
}
