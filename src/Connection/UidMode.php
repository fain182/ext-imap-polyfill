<?php

namespace ImapPolyfill\Connection;

/**
 * Which id space a wire operation addresses messages in. The values are
 * opaque: only identity comparisons against these two constants are
 * meaningful (they exist as ints, not an enum, because they travel through
 * the ConnectionBackend signatures the POP3 backend implements too).
 */
final class UidMode
{
    public const UID = 1;

    public const MSGNO = 3;

    /**
     * The id space an imap_* flags argument asks for. Each function spells
     * the bit differently — FT_UID, SE_UID, CP_UID, ST_UID — so the caller
     * names the one its own signature documents.
     */
    public static function fromFlags(int $flags, int $uidBit): int
    {
        return ($flags & $uidBit) ? self::UID : self::MSGNO;
    }
}
