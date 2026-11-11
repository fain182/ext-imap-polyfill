<?php

namespace ImapPolyfill\Mime;

/**
 * The MIME names c-client parses and writes with: body_types[] and
 * body_encodings[] as they start out, before any name is registered at run
 * time, indexed by the TYPE* and ENC* codes, and rfc822.c's tspecials.
 */
final class Tables
{
    public const TYPES = ['TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'MODEL', 'X-UNKNOWN'];

    public const ENCODINGS = ['7BIT', '8BIT', 'BINARY', 'BASE64', 'QUOTED-PRINTABLE', 'X-UNKNOWN'];

    /** What a MIME word (type, subtype, parameter) ends at, and what forces a parameter value into quotes. */
    public const TSPECIALS = " ()<>@,;:\\\"[]/?=\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        ."\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /** rfc822_default_subtype(). */
    public static function defaultSubtype(int $type): string
    {
        return match ($type) {
            TYPETEXT => 'PLAIN',
            TYPEMULTIPART => 'MIXED',
            TYPEMESSAGE => 'RFC822',
            TYPEAPPLICATION => 'OCTET-STREAM',
            TYPEAUDIO => 'BASIC',
            default => 'UNKNOWN',
        };
    }
}
