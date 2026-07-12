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
        if ($flags && ($flags & ~(OP_READONLY | OP_ANONYMOUS | OP_HALFOPEN | CL_EXPUNGE | OP_DEBUG
                | OP_SHORTCACHE | OP_SILENT | OP_PROTOTYPE | OP_SECURE)) !== 0) {
            throw new \ValueError('imap_open(): Argument #4 ($flags) must be a bitmask of the OP_* constants, and CL_EXPUNGE');
        }

        if ($retries < 0) {
            throw new \ValueError('imap_open(): Argument #5 ($retries) must be greater than or equal to 0');
        }

        try {
            $spec = MailboxSpec::parse($mailbox);
        } catch (\ValueError $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $backend = $spec->hasFlag('pop3')
            ? self::connectPop3($spec, $mailbox, $user, $password, $retries)
            : self::connectImap($spec, $user, $password, $retries);

        if ($backend === false) {
            return false;
        }

        // c-client treats a /readonly flag in the spec the same as passing
        // OP_READONLY (mail_valid_net_parse sets the stream read-only bit).
        $readOnly = (bool) ($flags & OP_READONLY) || $spec->hasFlag('readonly');
        $secure = $spec->hasFlag('secure') || (bool) ($flags & OP_SECURE);
        $service = $spec->hasFlag('pop3') ? 'pop3' : 'imap';
        $connection = new \IMAP\Connection(
            $backend,
            $spec->folder,
            $spec->normalizedPrefixBase($service, $secure),
            $user,
            $readOnly,
        );
        $connection->setExpungeOnClose((bool) ($flags & CL_EXPUNGE));

        try {
            $status = $connection->selectOrExamine();
            $connection->rememberCounts($status['exists'] ?? 0, $status['recent'] ?? 0);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return $connection;
    }

    private static function connectImap(MailboxSpec $spec, string $user, string $password, int $retries): \ImapPolyfill\Connection\ConnectionBackend|false
    {
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
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client->connect();

                return new \ImapPolyfill\Connection\Imap\ImapBackend($client);
            } catch (\Webklex\PHPIMAP\Exceptions\ConnectionFailedException $e) {
                // webklex's own message is a bare "connection failed";
                // c-client reports "Can't connect to host,port: reason", and
                // naming the attempted port is the only way the default-port
                // choice stays observable (and parity-testable) from outside.
                $reason = $e->getPrevious()?->getMessage() ?? $e->getMessage();
                ErrorStack::push("Can't connect to {$spec->host},{$spec->port}: {$reason}");
            } catch (\Throwable $e) {
                ErrorStack::push($e->getMessage());
            }
        }

        return false;
    }

    private static function connectPop3(MailboxSpec $spec, string $mailbox, string $user, string $password, int $retries): \ImapPolyfill\Connection\ConnectionBackend|false
    {
        $encryption = false;
        if ($spec->hasFlag('ssl')) {
            $encryption = 'ssl';
        } elseif ($spec->hasFlag('tls')) {
            $encryption = 'tls';
        }

        $attempts = 1 + max(0, $retries);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $protocol = new \ImapPolyfill\Connection\Pop3\Pop3Protocol();

            try {
                $protocol->connect($spec->host, $spec->port, $encryption, !$spec->hasFlag('novalidate-cert'));
                $protocol->login($user, $password);

                return new \ImapPolyfill\Connection\Pop3\Pop3Backend($protocol, $spec->host, $mailbox);
            } catch (\Throwable $e) {
                ErrorStack::push($e->getMessage());
            }
        }

        return false;
    }

    public function close(int $flags): bool
    {
        $this->connection->ensureOpen();

        if (($flags & ~CL_EXPUNGE) !== 0) {
            throw new \ValueError('imap_close(): Argument #2 ($flags) must be CL_EXPUNGE or 0');
        }

        // A flags argument here always wins outright (only CL_EXPUNGE or 0
        // are allowed); omitting it falls back to whatever CL_EXPUNGE state
        // was remembered from imap_open()/imap_reopen(), matching c-client's
        // stream->flags persisting across calls.
        $shouldExpunge = $flags !== 0
            ? (bool) ($flags & CL_EXPUNGE)
            : $this->connection->expungeOnClose();

        if ($shouldExpunge) {
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
        $result->Driver = $this->connection->driverName();
        $result->Mailbox = $this->connection->mailboxString();
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
        $result->Driver = $this->connection->driverName();
        $result->Mailbox = $this->connection->mailboxString();
        $result->Nmsgs = $exists;
        $result->Recent = $status['recent'] ?? 0;

        return $result;
    }

    /**
     * This polyfill keeps no client-side cache of message elements/envelopes/
     * texts to purge, so once the flags bitmask is validated there is
     * nothing left to do — matching ext-imap's own "always returns true"
     * contract (mail_gc()'s result isn't even checked in php_imap.c).
     */
    public function gc(int $flags): bool
    {
        $this->connection->ensureOpen();

        if ($flags && ($flags & ~(IMAP_GC_TEXTS | IMAP_GC_ELT | IMAP_GC_ENV)) !== 0) {
            throw new \ValueError('imap_gc(): Argument #2 ($flags) must be a bitmask of IMAP_GC_TEXTS, IMAP_GC_ELT, and IMAP_GC_ENV');
        }

        return true;
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
    public function reopen(string $mailbox, int $flags, int $retries = 0): bool
    {
        $this->connection->ensureOpen();

        if ($flags && ($flags & ~(OP_READONLY | OP_ANONYMOUS | OP_HALFOPEN | OP_EXPUNGE | CL_EXPUNGE)) !== 0) {
            throw new \ValueError('imap_reopen(): Argument #3 ($flags) must be a bitmask of OP_READONLY, OP_ANONYMOUS, OP_HALFOPEN, OP_EXPUNGE, and CL_EXPUNGE');
        }

        if ($retries < 0) {
            throw new \ValueError('imap_reopen(): Argument #4 ($retries) must be greater than or equal to 0');
        }

        try {
            $spec = MailboxSpec::parse($mailbox);
        } catch (\ValueError $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        // Like imap_open(): a /readonly flag in the spec counts as OP_READONLY.
        $readOnly = (bool) ($flags & OP_READONLY) || $spec->hasFlag('readonly');

        try {
            $status = $this->connection->selectOrExamineFolder($spec->folder, $readOnly);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $this->connection->reselect($spec->folder, $readOnly);
        $this->connection->rememberCounts($status['exists'] ?? 0, $status['recent'] ?? 0);

        // Mirrors php_imap.c: a nonzero $flags overrides the remembered
        // CL_EXPUNGE state outright (even clearing it if CL_EXPUNGE isn't in
        // the new bitmask); $flags === 0 leaves whatever imap_open() set.
        if ($flags !== 0) {
            $this->connection->setExpungeOnClose((bool) ($flags & CL_EXPUNGE));
        }

        return true;
    }
}
