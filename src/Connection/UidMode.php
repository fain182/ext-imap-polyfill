<?php

namespace ImapPolyfill\Connection;

/**
 * Which id space a wire operation addresses messages in, as the type the
 * polyfill carries past the imap_* boundary.
 *
 * An enum rather than the raw bit, so that every `match` over the two is
 * exhaustive by construction: a third id space added here without an arm
 * everywhere is a static-analysis error rather than an array index nobody
 * finds until a message set is refused in the wrong words.
 */
enum UidMode
{
    case Uid;

    case Msgno;

    /**
     * The id space an imap_* flags argument asks for. Each function spells
     * the bit differently — FT_UID, SE_UID, CP_UID, ST_UID — so the caller
     * names the one its own signature documents.
     */
    public static function fromFlags(int $flags, int $uidBit): self
    {
        return ($flags & $uidBit) ? self::Uid : self::Msgno;
    }
}
