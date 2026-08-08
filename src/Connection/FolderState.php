<?php

namespace ImapPolyfill\Connection;

/**
 * What a SELECT/EXAMINE reported about the folder it selected.
 *
 * A value object rather than the keyed array this used to be. The counters
 * are the whole point of selecting, and a caller handed one without them has
 * no sensible answer: as an array they were read as `$status['exists'] ?? 0`
 * in fourteen places, so a count that never arrived reported the folder
 * empty rather than reporting a failure. Now whoever builds one has to have
 * both counts in hand, and no caller has anything to guard against.
 */
final class FolderState
{
    /**
     * @param int           $recent 0 when the server sends no RECENT, which is
     *                              its choice to make: IMAP4rev2 deprecated
     *                              \Recent. An absent EXISTS is a different
     *                              matter and never gets this far.
     * @param list<string>  $flags  the flag names the FLAGS response advertised;
     *                              where IMAP\Connection picks up the keywords
     *                              it registers
     */
    public function __construct(
        public readonly int $exists,
        public readonly int $recent,
        public readonly ?int $uidValidity = null,
        public readonly array $flags = [],
    ) {
    }
}
