<?php

namespace IMAP;

use ImapPolyfill\Connection\Protocol;
use ImapPolyfill\Message\BodyStructureFetch;

/**
 * Polyfill for the opaque IMAP\Connection class ext-imap registers natively.
 * Owns the webklex client: nothing outside this class touches it directly,
 * so every wire operation Session/Mailbox/Mailboxes need is exposed here as
 * a named method instead of reaching through a public "client" field.
 */
final class Connection
{
    private readonly \Webklex\PHPIMAP\Client $client;

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
        \Webklex\PHPIMAP\Client $client,
        string $folder,
        public readonly string $mailbox,
        bool $readOnly = false,
    ) {
        $this->client = $client;
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
        $folderObj = $this->client->getFolder($folder);

        return $readOnly ? $folderObj->examine() : $folderObj->select();
    }

    public function protocol(): Protocol
    {
        return $this->protocol ??= new Protocol($this->client);
    }

    public function host(): string
    {
        return $this->client->host;
    }

    public function expunge(): void
    {
        $this->client->expunge();
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
    }

    public function createFolder(string $name): void
    {
        $this->client->createFolder($name);
    }

    public function deleteFolder(string $name): void
    {
        $this->client->deleteFolder($name);
    }

    public function renameFolder(string $from, string $to): void
    {
        $this->client->getFolder($from)->rename($to);
    }

    public function subscribeFolder(string $name): void
    {
        $this->client->getFolder($name)->subscribe();
    }

    public function unsubscribeFolder(string $name): void
    {
        $this->client->getFolder($name)->unsubscribe();
    }

    /**
     * @param string[]|null $flags
     */
    public function appendMessage(string $folder, string $message, ?array $flags, ?string $internalDate): void
    {
        $this->client->getFolder($folder)->appendMessage($message, $flags, $internalDate);
    }

    /**
     * @return array<int, mixed>
     */
    public function fetchBodyStructure(int $messageNum, bool $byUid): array
    {
        return BodyStructureFetch::fetch($this->client, $messageNum, $byUid);
    }
}
