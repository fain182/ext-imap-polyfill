<?php

namespace ImapPolyfill;

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

    private static function buildMultipart(array $parsed): \stdClass
    {
        $result = new \stdClass();
        $result->type = self::TYPES['multipart'];
        $result->encoding = 0; // ENC7BIT: multipart bodies carry no encoding atom of their own

        $parts = [];
        $i = 0;
        while (isset($parsed[$i]) && is_array($parsed[$i])) {
            $parts[] = self::build($parsed[$i]);
            $i++;
        }

        self::assignSubtype($result, $parsed[$i] ?? null);
        $i++;

        // multipart bodies have no id/description slots on the wire; ext-imap
        // still reports the flags as false rather than omitting them.
        $result->ifdescription = 0;
        $result->ifid = 0;

        self::assignParameters($result, $parsed[$i] ?? null);
        $i++;

        self::assignDisposition($result, $parsed[$i] ?? null);

        $result->parts = $parts;

        return $result;
    }

    private static function buildSinglePart(array $parsed): \stdClass
    {
        $result = new \stdClass();

        $type = strtolower((string) $parsed[0]);
        $result->type = self::TYPES[$type] ?? 8; // TYPEOTHER

        self::assignSubtype($result, $parsed[1] ?? null);
        self::assignParameters($result, $parsed[2] ?? null);
        self::assignIfSet($result, 'id', $parsed[3] ?? null);
        self::assignIfSet($result, 'description', $parsed[4] ?? null);

        $encoding = strtolower((string) ($parsed[5] ?? ''));
        $result->encoding = self::ENCODINGS[$encoding] ?? 5; // ENCOTHER

        if (!empty($parsed[6])) {
            $result->bytes = $parsed[6];
        }

        $next = 7;
        if ($result->type === self::TYPES['text']) {
            if (!empty($parsed[$next])) {
                $result->lines = $parsed[$next];
            }
            $next++;
        } elseif ($result->type === self::TYPES['message'] && strtolower($result->subtype ?? '') === 'rfc822') {
            $next++; // skip envelope
            $result->parts = [self::build($parsed[$next])];
            $next++;
            if (!empty($parsed[$next])) {
                $result->lines = $parsed[$next];
            }
            $next++;
        }

        $next++; // skip md5
        self::assignDisposition($result, $parsed[$next] ?? null);

        return $result;
    }

    private static function assignSubtype(\stdClass $result, mixed $subtype): void
    {
        if ($subtype !== null) {
            $result->ifsubtype = 1;
            $result->subtype = strtoupper($subtype);
        } else {
            $result->ifsubtype = 0;
        }
    }

    private static function assignIfSet(\stdClass $result, string $property, mixed $value): void
    {
        if ($value !== null) {
            $result->{'if'.$property} = 1;
            $result->$property = $value;
        } else {
            $result->{'if'.$property} = 0;
        }
    }

    private static function assignParameters(\stdClass $result, mixed $params): void
    {
        if (empty($params)) {
            $result->ifparameters = 0;
            $result->parameters = new \stdClass();

            return;
        }

        $result->ifparameters = 1;
        $result->parameters = self::pairsToObjects($params);
    }

    private static function assignDisposition(\stdClass $result, mixed $disposition): void
    {
        if (empty($disposition)) {
            $result->ifdisposition = 0;
            $result->ifdparameters = 0;
            $result->dparameters = new \stdClass();

            return;
        }

        $result->ifdisposition = 1;
        $result->disposition = $disposition[0];

        if (empty($disposition[1])) {
            $result->ifdparameters = 0;
            $result->dparameters = new \stdClass();

            return;
        }

        $result->ifdparameters = 1;
        $result->dparameters = self::pairsToObjects($disposition[1]);
    }

    /**
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
