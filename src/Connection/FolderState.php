<?php

namespace ImapPolyfill\Connection;

/**
 * What a SELECT/EXAMINE reported about the folder it selected.
 *
 * The counters are the whole point of selecting, so they are constructor
 * arguments rather than optional keys: whoever builds one has to have both
 * counts in hand, and no caller has anything to guard against. A count that
 * never arrived is a failure to raise, not a folder reporting itself empty.
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
