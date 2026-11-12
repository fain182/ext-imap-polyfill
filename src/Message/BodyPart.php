<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Mime\Tables;

/**
 * c-client's BODY: one body part of a message, as the IMAP backend reads it
 * from BODYSTRUCTURE and the POP3 backend parses it out of the message.
 * Each backend can fill in only what its protocol gives it: an IMAP
 * multipart has no size, a POP3 one does.
 */
final class BodyPart
{
    public const TYPE_MULTIPART = 1;

    public const TYPE_MESSAGE = 2;

    /**
     * A name c-client registers at run time gets the next free slot of the
     * table rather than TYPEOTHER/ENCOTHER. That index depends on what the
     * process has met before, request after request under FPM, so no caller
     * can rely on it; the unknown ones stay at the fixed codes here.
     *
     * @param list<array{0: string, 1: string}> $parameters
     * @param list<array{0: string, 1: string}> $dispositionParameters
     * @param list<self> $parts a multipart's parts, or the one body of an
     *   embedded message — none where that message is empty, as an empty
     *   part of a digest is
     */
    public function __construct(
        public readonly int $type,
        public readonly int $encoding,
        public readonly ?string $subtype,
        public readonly array $parameters,
        public readonly ?string $id,
        public readonly ?string $description,
        public readonly int $bytes,
        public readonly int $lines,
        public readonly ?string $disposition,
        public readonly array $dispositionParameters,
        public readonly array $parts,
    ) {
    }

    public static function typeCode(string $name): int
    {
        $code = array_search(strtoupper($name), Tables::TYPES, true);

        return $code === false ? TYPEOTHER : $code;
    }

    public static function encodingCode(string $name): int
    {
        $code = array_search(strtoupper($name), Tables::ENCODINGS, true);

        return $code === false ? ENCOTHER : $code;
    }

    public function isEmbeddedMessage(): bool
    {
        return $this->type === self::TYPE_MESSAGE && $this->subtype === 'RFC822';
    }

    /**
     * A parenthesized BODYSTRUCTURE list as ImapSexpParser decodes it: a
     * multipart if its first element is itself a list.
     *
     * @param array<int, mixed> $parsed
     */
    public static function fromBodyStructure(array $parsed): self
    {
        if (is_array($parsed[0])) {
            $parts = [];
            $i = 0;
            while (isset($parsed[$i]) && is_array($parsed[$i])) {
                $parts[] = self::fromBodyStructure($parsed[$i]);
                $i++;
            }

            return new self(
                type: self::TYPE_MULTIPART,
                encoding: 0, // ENC7BIT: multipart bodies carry no encoding atom of their own
                subtype: self::upper($parsed[$i] ?? null) ?? Tables::defaultSubtype(self::TYPE_MULTIPART),
                parameters: self::pairs($parsed[$i + 1] ?? null),
                id: null,
                description: null,
                bytes: 0,
                lines: 0,
                disposition: self::dispositionType($parsed[$i + 2] ?? null),
                dispositionParameters: self::pairs($parsed[$i + 2][1] ?? null),
                parts: $parts,
            );
        }

        $type = self::typeCode((string) $parsed[0]);
        $subtype = self::upper($parsed[1] ?? null) ?? Tables::defaultSubtype($type);
        $lines = 0;
        $parts = [];

        $next = 7;
        if ($type === 0) {
            $lines = (int) ($parsed[$next] ?? 0);
            $next++;
        } elseif ($type === self::TYPE_MESSAGE && $subtype === 'RFC822') {
            $next++; // skip envelope
            if (is_array($parsed[$next] ?? null)) {
                $parts = [self::fromBodyStructure($parsed[$next])];
            }
            $next++;
            $lines = (int) ($parsed[$next] ?? 0);
            $next++;
        }

        $next++; // skip md5

        // A field the server left out reads as imap_parse_body_structure()
        // reads it: bytes and lines as strtoul() of nothing, 0, and the
        // encoding as an atom it does not know, ENCOTHER.
        return new self(
            type: $type,
            encoding: self::encodingCode((string) ($parsed[5] ?? '')),
            subtype: $subtype,
            parameters: self::pairs($parsed[2] ?? null),
            id: self::stringOrNull($parsed[3] ?? null),
            description: self::stringOrNull($parsed[4] ?? null),
            bytes: (int) ($parsed[6] ?? 0),
            lines: $lines,
            disposition: self::dispositionType($parsed[$next] ?? null),
            dispositionParameters: self::pairs($parsed[$next][1] ?? null),
            parts: $parts,
        );
    }

    private static function upper(mixed $value): ?string
    {
        return $value === null ? null : strtoupper((string) $value);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function dispositionType(mixed $disposition): ?string
    {
        return is_array($disposition) && isset($disposition[0]) ? (string) $disposition[0] : null;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function pairs(mixed $flat): array
    {
        if (!is_array($flat)) {
            return [];
        }

        // imap_parse_body_parameter() names an attribute or a value the
        // server left out, or sent as NIL, "UNKNOWN".
        $pairs = [];
        $flat = array_values($flat);
        for ($i = 0; $i < count($flat); $i += 2) {
            $pairs[] = [(string) ($flat[$i] ?? 'UNKNOWN'), (string) ($flat[$i + 1] ?? 'UNKNOWN')];
        }

        return $pairs;
    }
}
