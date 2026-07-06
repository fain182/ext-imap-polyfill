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

    /** Mirrors c-client's stream->recent; see $cachedNumMsg. */
    public int $cachedNumRecent = 0;

    public function __construct(
        public readonly \Webklex\PHPIMAP\Client $client,
        public string $folder,
        public readonly string $mailbox,
        public bool $readOnly = false,
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

    /**
     * Re-selects the current folder before an operation, the way every
     * wrapper function needs to. Uses EXAMINE instead of SELECT when the
     * connection was opened with OP_READONLY, so a read-only imap_open()
     * doesn't get silently escalated back to read-write on the next call —
     * matching ext-imap, the read-only guarantee itself is enforced by the
     * IMAP server rejecting writes, not by this client.
     *
     * @return array<string, mixed>
     */
    public function selectOrExamine(): array
    {
        $folder = $this->client->getFolder($this->folder);

        return $this->readOnly ? $folder->examine() : $folder->select();
    }
}
