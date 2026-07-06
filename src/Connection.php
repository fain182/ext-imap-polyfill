<?php

namespace IMAP;

/**
 * Polyfill for the opaque IMAP\Connection class ext-imap registers natively.
 * Holds the webklex client plus the currently selected folder path.
 */
final class Connection
{
    public bool $closed = false;

    /**
     * Mirrors c-client's stream->nmsgs: imap_num_msg() is a cached client-side
     * read, not a live query, so it must keep returning the last known count
     * (not false/0) if the connection later breaks.
     */
    public int $cachedNumMsg = 0;

    public function __construct(
        public readonly \Webklex\PHPIMAP\Client $client,
        public string $folder,
        public readonly string $mailbox,
    ) {
    }

    /**
     * Matches ext-imap: any function used on a stream after imap_close()
     * throws, rather than failing silently.
     */
    public function ensureOpen(): void
    {
        if ($this->closed) {
            throw new \ValueError('IMAP\Connection is already closed');
        }
    }
}
