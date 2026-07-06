<?php

namespace IMAP;

use ImapPolyfill\Connection\Protocol;

/**
 * Polyfill for the opaque IMAP\Connection class ext-imap registers natively.
 * Holds the webklex client plus the currently selected folder path.
 */
final class Connection
{
    private ?Protocol $protocol = null;

    private bool $closed = false;

    private string $folder;

    private bool $readOnly;

    /**
     * Mirrors c-client's stream->nmsgs: imap_num_msg() is a cached client-side
     * read, not a live query, so it must keep returning the last known count
     * (not false/0) if the connection later breaks.
     */
    private int $cachedNumMsg = 0;

    /** Mirrors c-client's stream->recent; see $cachedNumMsg. */
    private int $cachedNumRecent = 0;

    public function __construct(
        public readonly \Webklex\PHPIMAP\Client $client,
        string $folder,
        public readonly string $mailbox,
        bool $readOnly = false,
    ) {
        $this->folder = $folder;
        $this->readOnly = $readOnly;
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

    public function isOpen(): bool
    {
        return !$this->closed;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function numMessages(): int
    {
        return $this->cachedNumMsg;
    }

    public function numRecent(): int
    {
        return $this->cachedNumRecent;
    }

    public function rememberCounts(int $numMessages, int $numRecent): void
    {
        $this->cachedNumMsg = $numMessages;
        $this->cachedNumRecent = $numRecent;
    }

    /**
     * Switches the currently selected folder and read-only mode, e.g. after
     * imap_reopen(). Does not touch the underlying IMAP session itself —
     * callers must SELECT/EXAMINE the new folder on the client separately.
     */
    public function reselect(string $folder, bool $readOnly): void
    {
        $this->folder = $folder;
        $this->readOnly = $readOnly;
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

    public function protocol(): Protocol
    {
        return $this->protocol ??= new Protocol($this->client);
    }
}
