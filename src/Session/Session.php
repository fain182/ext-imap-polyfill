<?php

namespace ImapPolyfill\Session;

use ImapPolyfill\Mailbox\MailboxSpec;
use ImapPolyfill\Support\ErrorStack;

/**
 * Lifecycle of an \IMAP\Connection: opening it, closing it, reopening it
 * onto a different folder, and the cached count/status reads that go with
 * it. Operations on the selected mailbox's messages live in Mailbox, and
 * folder-hierarchy operations in MailboxHierarchy.
 */
final class Session
{
    public function __construct(private readonly \IMAP\Connection $connection)
    {
    }

    /**
     * The body of imap_open(): builds a webklex client from the mailbox spec,
     * connects (retrying up to $retries extra times), and selects the spec's
     * folder. Returns false instead of throwing on any failure — bad spec,
     * unreachable host, missing folder — pushing the cause onto the
     * ErrorStack; the shim in functions.php only adds the user-facing warning.
     */
    public static function open(string $mailbox, string $user, string $password, int $flags, int $retries): \IMAP\Connection|false
    {
        try {
            $spec = MailboxSpec::parse($mailbox);
        } catch (\ValueError $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $encryption = false;
        if ($spec->hasFlag('ssl')) {
            $encryption = 'ssl';
        } elseif ($spec->hasFlag('tls')) {
            $encryption = 'tls';
        }

        $client = (new \Webklex\PHPIMAP\ClientManager())->make([
            'host' => $spec->host,
            'port' => $spec->port,
            'encryption' => $encryption,
            'validate_cert' => !$spec->hasFlag('novalidate-cert'),
            'username' => $user,
            'password' => $password,
            'protocol' => 'imap',
        ]);

        $attempts = 1 + max(0, $retries);
        $connected = false;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client->connect();
                $connected = true;
                break;
            } catch (\Throwable $e) {
                ErrorStack::push($e->getMessage());
            }
        }

        if (!$connected) {
            return false;
        }

        $connection = new \IMAP\Connection($client, $spec->folder, $mailbox, (bool) ($flags & OP_READONLY));

        try {
            $status = $connection->selectOrExamine();
            $connection->rememberCounts($status['exists'] ?? 0, $status['recent'] ?? 0);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return $connection;
    }

    public function close(int $flags): bool
    {
        $this->connection->ensureOpen();

        if (($flags & ~CL_EXPUNGE) !== 0) {
            throw new \ValueError('imap_close(): Argument #2 ($flags) must be CL_EXPUNGE or 0');
        }

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

    public function mailboxMsgInfo(): \stdClass|false
    {
        $this->connection->ensureOpen();

        try {
            $status = $this->connection->selectOrExamine();
            $exists = $status['exists'] ?? 0;

            $unread = 0;
            $deleted = 0;
            $size = 0;
            if ($exists > 0) {
                $data = $this->connection->protocol()->fetch(
                    ['FLAGS', 'RFC822.SIZE'],
                    range(1, $exists),
                    null,
                    \Webklex\PHPIMAP\IMAP::ST_MSGN,
                );

                foreach ($data as $message) {
                    $flags = $message['FLAGS'];
                    // c-client counts a message as unread when it is unseen
                    // *or* recent (MESSAGECACHE's `!seen || recent`), not
                    // just unseen.
                    if (!in_array('\\Seen', $flags, true) || in_array('\\Recent', $flags, true)) {
                        $unread++;
                    }

                    if (in_array('\\Deleted', $flags, true)) {
                        $deleted++;
                    }

                    $size += (int) $message['RFC822.SIZE'];
                }
            }
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $result = new \stdClass();
        $result->Unread = $unread;
        $result->Deleted = $deleted;
        $result->Size = $size;
        $result->Date = date('r');
        $result->Driver = 'imap';
        $result->Mailbox = $this->connection->mailbox;
        $result->Nmsgs = $exists;
        $result->Recent = $status['recent'] ?? 0;

        return $result;
    }

    public function ping(): bool
    {
        $this->connection->ensureOpen();

        try {
            $this->connection->protocol()->noop();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
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
