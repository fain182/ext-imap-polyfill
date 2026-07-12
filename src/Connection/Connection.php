<?php

namespace IMAP;

use ImapPolyfill\Connection\ConnectionBackend;

/**
 * Polyfill for the opaque IMAP\Connection class ext-imap registers natively.
 * Holds connection-level state (selected folder, read-only flag, cached
 * counters) and delegates every wire operation to a ConnectionBackend —
 * either Connection\Imap\ImapBackend (webklex/IMAP) or Connection\Pop3\Pop3Backend
 * (POP3), chosen by Session::open() from the mailbox spec. Knows nothing
 * about imap_* contracts, ErrorStack, or return-value conventions.
 */
final class Connection
{
    private readonly ConnectionBackend $backend;

    private bool $closed = false;

    private string $folder;

    private bool $readOnly;

    /**
     * Mirrors c-client stream flags carried across imap_open()/imap_reopen():
     * when CL_EXPUNGE was passed, imap_close() auto-expunges even if called
     * with no flags of its own.
     */
    private bool $expungeOnClose = false;

    /**
     * Mirrors c-client's stream->nmsgs: imap_num_msg() is a cached client-side
     * read, not a live query, so it must keep returning the last known count
     * (not false/0) if the connection later breaks.
     */
    private int $cachedNumMsg = 0;

    /** Mirrors c-client's stream->recent; see $cachedNumMsg. */
    private int $cachedNumRecent = 0;

    /**
     * Mirrors c-client's stream->user_flags: the custom keywords this
     * session knows about, in registration order, fed by the SELECT FLAGS
     * responses. imap_headers' "{flag}" segment renders in this order and
     * omits keywords the session never saw listed — observed real-ext-imap
     * behavior: against a server that leaves keywords out of FLAGS (e.g.
     * GreenMail), the segment stays empty even for keywords this same
     * session just stored.
     *
     * @var string[]
     */
    private array $userFlags = [];

    public function __construct(
        ConnectionBackend $backend,
        string $folder,
        public readonly string $mailbox,
        bool $readOnly = false,
    ) {
        $this->backend = $backend;
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

    public function expungeOnClose(): bool
    {
        return $this->expungeOnClose;
    }

    public function setExpungeOnClose(bool $expungeOnClose): void
    {
        $this->expungeOnClose = $expungeOnClose;
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
     * imap_reopen(). Does not touch the underlying session itself — callers
     * must select/examine the new folder on the backend separately.
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
        return $this->selectOrExamineFolder($this->folder, $this->readOnly);
    }

    /**
     * Selects/examines a folder other than the currently-remembered one,
     * e.g. to probe a target folder before imap_reopen() commits to it via
     * reselect(). Does not touch $this->folder/$this->readOnly itself.
     *
     * @return array<string, mixed>
     */
    public function selectOrExamineFolder(string $folder, bool $readOnly): array
    {
        $data = $this->backend->selectOrExamineFolder($folder, $readOnly);

        foreach ($data['flags'] ?? [] as $flagList) {
            if (is_array($flagList)) {
                $this->registerUserFlags($flagList);
            }
        }

        return $data;
    }

    /**
     * @param string[] $flags system flags are ignored, keywords registered
     */
    public function registerUserFlags(array $flags): void
    {
        foreach ($flags as $flag) {
            if (!str_starts_with($flag, '\\') && !in_array($flag, $this->userFlags, true)) {
                $this->userFlags[] = $flag;
            }
        }
    }

    /**
     * @return string[]
     */
    public function userFlags(): array
    {
        return $this->userFlags;
    }

    /**
     * Exposes the message/folder wire operations (search, fetch, store,
     * folders, ...) of the current backend; named protocol() for historical
     * reasons — it returns the whole ConnectionBackend, not just the subset
     * that used to live on the (now-removed) Protocol-only accessor.
     */
    public function protocol(): ConnectionBackend
    {
        return $this->backend;
    }

    public function host(): string
    {
        return $this->backend->host();
    }

    public function driverName(): string
    {
        return $this->backend->driverName();
    }

    public function expunge(): void
    {
        $this->backend->expunge();
    }

    public function disconnect(): void
    {
        $this->backend->disconnect();
    }

    public function createFolder(string $name): void
    {
        $this->backend->createFolder($name);
    }

    public function deleteFolder(string $name): void
    {
        $this->backend->deleteFolder($name);
    }

    public function renameFolder(string $from, string $to): void
    {
        $this->backend->renameFolder($from, $to);
    }

    public function subscribeFolder(string $name): void
    {
        $this->backend->subscribeFolder($name);
    }

    public function unsubscribeFolder(string $name): void
    {
        $this->backend->unsubscribeFolder($name);
    }

    /**
     * @param string[]|null $flags
     */
    public function appendMessage(string $folder, string $message, ?array $flags, ?string $internalDate): void
    {
        $this->backend->appendMessage($folder, $message, $flags, $internalDate);
    }

    /**
     * @return array<int, mixed>
     */
    public function fetchBodyStructure(int $messageNum, bool $byUid): array
    {
        return $this->backend->fetchBodyStructure($messageNum, $byUid);
    }
}
