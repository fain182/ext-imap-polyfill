<?php

namespace ImapPolyfill\Session;

use ImapPolyfill\Mailbox\MailboxSpec;
use ImapPolyfill\Support\ErrorStack;

/**
 * Lifecycle of an already-open \IMAP\Connection: closing it, reopening it
 * onto a different folder, and the cached count/status reads that go with
 * it. Operations on the selected mailbox's messages live in Mailbox, and
 * folder-hierarchy operations in MailboxHierarchy.
 */
final class Session
{
    public function __construct(private readonly \IMAP\Connection $connection)
    {
    }

    public function close(int $flags): bool
    {
        $this->connection->ensureOpen();

        if ($flags & CL_EXPUNGE) {
            $this->connection->expunge();
        }

        $this->connection->disconnect();
        $this->connection->close();

        return true;
    }

    public function numMessages(): int|false
    {
        $this->connection->ensureOpen();

        try {
            $status = $this->connection->selectOrExamine();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            // ext-imap's imap_num_msg is a cached client-side read (c-client's
            // stream->nmsgs), not a live query: it keeps returning the last
            // known count rather than false if the connection later breaks.
            return $this->connection->numMessages();
        }

        $this->connection->rememberCounts($status['exists'] ?? 0, $status['recent'] ?? 0);

        return $this->connection->numMessages();
    }

    public function numRecent(): int|false
    {
        $this->connection->ensureOpen();

        try {
            $status = $this->connection->selectOrExamine();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            // Cached client-side read, like numMessages(); see its comment.
            return $this->connection->numRecent();
        }

        $this->connection->rememberCounts($status['exists'] ?? 0, $status['recent'] ?? 0);

        return $this->connection->numRecent();
    }

    public function check(): \stdClass|false
    {
        $this->connection->ensureOpen();

        try {
            $status = $this->connection->selectOrExamine();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $result = new \stdClass();
        $result->Date = date('r');
        $result->Driver = 'imap';
        $result->Mailbox = $this->connection->mailbox;
        $result->Nmsgs = $status['exists'] ?? 0;
        $result->Recent = $status['recent'] ?? 0;

        return $result;
    }

    public function isOpen(): bool
    {
        return $this->connection->isOpen();
    }

    /**
     * Scoped to switching folders on the same already-connected client: this
     * polyfill doesn't retain the original credentials needed to reconnect
     * to a genuinely different host.
     */
    public function reopen(string $mailbox, int $flags): bool
    {
        $this->connection->ensureOpen();

        try {
            $spec = MailboxSpec::parse($mailbox);
        } catch (\ValueError $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $readOnly = (bool) ($flags & OP_READONLY);

        try {
            $status = $this->connection->selectOrExamineFolder($spec->folder, $readOnly);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $this->connection->reselect($spec->folder, $readOnly);
        $this->connection->rememberCounts($status['exists'] ?? 0, $status['recent'] ?? 0);

        return true;
    }
}
