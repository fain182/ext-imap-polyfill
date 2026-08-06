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
}
