<?php

namespace ImapPolyfill\Session;

use ImapPolyfill\Mailbox\MailboxSpec;
use ImapPolyfill\Support\ErrorStack;

/**
 * Entry point for a single imap_*() call against an already-open
 * \IMAP\Connection. Owns the connection's own lifecycle (open/closed,
 * cached counts, current folder); mailbox-selection and hierarchy
 * operations are delegated to Mailbox and MailboxHierarchy respectively.
 */
final class Session
{
    private readonly Mailbox $mailbox;

    private readonly MailboxHierarchy $mailboxHierarchy;

    public function __construct(private readonly \IMAP\Connection $connection)
    {
        $this->mailbox = new Mailbox($connection);
        $this->mailboxHierarchy = new MailboxHierarchy($connection);
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

    /**
     * @return int[]|false
     */
    public function search(string $criteria, int $flags, string $charset): array|false
    {
        return $this->mailbox->search($criteria, $flags, $charset);
    }

    public function fetchHeader(int $messageNum, int $flags): string|false
    {
        return $this->mailbox->fetchHeader($messageNum, $flags);
    }

    public function headerInfo(int $messageNum): \stdClass|false
    {
        return $this->mailbox->headerInfo($messageNum);
    }

    /**
     * @return \stdClass[]|false
     */
    public function fetchOverview(string $sequence, int $flags): array|false
    {
        return $this->mailbox->fetchOverview($sequence, $flags);
    }

    public function fetchStructure(int $messageNum, int $flags): \stdClass|false
    {
        return $this->mailbox->fetchStructure($messageNum, $flags);
    }

    public function fetchBody(int $messageNum, string $section, int $flags): string|false
    {
        return $this->mailbox->fetchBody($messageNum, $section, $flags);
    }

    public function uid(int $messageNum): int|false
    {
        return $this->mailbox->uid($messageNum);
    }

    public function msgno(int $messageUid): int
    {
        return $this->mailbox->msgno($messageUid);
    }

    public function setFlagFull(string $sequence, string $flag, int $options): bool
    {
        return $this->mailbox->setFlagFull($sequence, $flag, $options);
    }

    public function clearFlagFull(string $sequence, string $flag, int $options): bool
    {
        return $this->mailbox->clearFlagFull($sequence, $flag, $options);
    }

    public function expunge(): bool
    {
        return $this->mailbox->expunge();
    }

    public function append(string $folder, string $message, ?string $options, ?string $internalDate): bool
    {
        return $this->mailbox->append($folder, $message, $options, $internalDate);
    }

    /**
     * @return string[]|false
     */
    public function listMailboxes(string $reference, string $pattern): array|false
    {
        return $this->mailboxHierarchy->listMailboxes($reference, $pattern);
    }

    /**
     * @return \stdClass[]|false
     */
    public function getMailboxes(string $reference, string $pattern): array|false
    {
        return $this->mailboxHierarchy->getMailboxes($reference, $pattern);
    }

    public function createMailbox(string $mailbox): bool
    {
        return $this->mailboxHierarchy->createMailbox($mailbox);
    }

    public function deleteMailbox(string $mailbox): bool
    {
        return $this->mailboxHierarchy->deleteMailbox($mailbox);
    }

    public function renameMailbox(string $from, string $to): bool
    {
        return $this->mailboxHierarchy->renameMailbox($from, $to);
    }

    public function subscribe(string $mailbox): bool
    {
        return $this->mailboxHierarchy->subscribe($mailbox);
    }

    public function unsubscribe(string $mailbox): bool
    {
        return $this->mailboxHierarchy->unsubscribe($mailbox);
    }
}
