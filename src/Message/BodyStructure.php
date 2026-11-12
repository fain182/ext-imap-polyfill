<?php

namespace ImapPolyfill\Message;

final class BodyStructure
{
    /**
     * The object imap_fetchstructure() returns: populate()'s properties and
     * `parts` last, as _php_imap_add_body() adds it.
     */
    public static function build(BodyPart $body): \stdClass
    {
        $result = self::populate($body);

        // An empty part of a digest is a message/rfc822 with no body in it,
        // which _php_imap_add_body() goes on to read through a NULL pointer.
        // No parts is the nearest answer that does not crash.
        if ($body->type === BodyPart::TYPE_MULTIPART || $body->parts !== []) {
            $result->parts = array_map(self::build(...), $body->parts);
        }

        return $result;
    }

    /**
     * The object imap_bodystruct() returns: php_imap.c fills it with
     * php_imap_populate_body_struct_object() alone and never calls
     * _php_imap_add_body(), so a multipart section comes back without
     * `parts`. The properties are in that function's order, which is what
     * var_dump(), json_encode() and a foreach over the object show.
     */
    public static function populate(BodyPart $body): \stdClass
    {
        $result = new \stdClass();
        $result->type = $body->type;
        $result->encoding = $body->encoding;

        $result->ifsubtype = $body->subtype !== null ? 1 : 0;
        if ($body->subtype !== null) {
            $result->subtype = $body->subtype;
        }

        $result->ifdescription = $body->description !== null ? 1 : 0;
        if ($body->description !== null) {
            $result->description = $body->description;
        }

        $result->ifid = $body->id !== null ? 1 : 0;
        if ($body->id !== null) {
            $result->id = $body->id;
        }

        // c-client's size fields are unsigned longs php_imap.c tests for
        // non-zero: a zero count is left out, not reported as 0.
        if ($body->lines !== 0) {
            $result->lines = $body->lines;
        }
        if ($body->bytes !== 0) {
            $result->bytes = $body->bytes;
        }

        $result->ifdisposition = $body->disposition !== null ? 1 : 0;
        if ($body->disposition !== null) {
            $result->disposition = $body->disposition;
        }

        $result->ifdparameters = $body->dispositionParameters !== [] ? 1 : 0;
        if ($body->dispositionParameters !== []) {
            $result->dparameters = self::pairsToObjects($body->dispositionParameters);
        }

        $result->ifparameters = $body->parameters !== [] ? 1 : 0;
        $result->parameters = $body->parameters === [] ? new \stdClass() : self::pairsToObjects($body->parameters);

        return $result;
    }

    /**
     * c-client's mail_body(): walks a dotted section path ("1", "2.1", ...)
     * down to the body it names, indexing into a multipart's parts and
     * stepping into an embedded message, whose parts are numbered as if it
     * were the body part itself (RFC 3501 §6.4.5).
     */
    public static function resolveSection(BodyPart $body, string $section): ?BodyPart
    {
        if ($section === '' || !preg_match('/^\d+(\.\d+)*$/', $section)) {
            return null;
        }

        $segments = array_map('intval', explode('.', $section));

        foreach ($segments as $index => $number) {
            if ($number < 1) {
                return null;
            }

            if ($body->type === BodyPart::TYPE_MULTIPART) {
                $body = $body->parts[$number - 1] ?? null;
                if ($body === null) {
                    return null;
                }
            } elseif ($number !== 1) {
                // Singlepart: index 1 names the part itself; anything else is invalid.
                return null;
            }

            if ($index === count($segments) - 1) {
                break;
            }

            if ($body->isEmbeddedMessage()) {
                $body = $body->parts[0] ?? null;
                if ($body === null) {
                    return null;
                }
            } elseif ($body->type !== BodyPart::TYPE_MULTIPART) {
                return null;
            }
        }

        return $body;
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     * @return \stdClass[]
     */
    private static function pairsToObjects(array $pairs): array
    {
        return array_map(static function (array $pair): \stdClass {
            $object = new \stdClass();
            $object->attribute = $pair[0];
            $object->value = $pair[1];

            return $object;
        }, $pairs);
    }
}
