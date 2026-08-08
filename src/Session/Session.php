<?php

namespace ImapPolyfill\Session;

use ImapPolyfill\Connection\Credentials;
use ImapPolyfill\Connection\FolderState;
use ImapPolyfill\Connection\UidMode;
use ImapPolyfill\Mailbox\MailboxSpec;
use ImapPolyfill\Mailbox\Service;
use ImapPolyfill\Support\ErrorStack;
use ImapPolyfill\Support\Timeouts;

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
     * The body of imap_open(): builds a connection from the mailbox spec,
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

        // Refused rather than quietly opened over IMAP, which is what
        // happened before and is worse than any error.
        if ($spec->service === Service::Nntp) {
            throw \ImapPolyfill\Support\UnsupportedFeature::nntp($mailbox);
        }

        $credentials = new Credentials(
            // php_imap.c's mm_login() answers c-client with the spec's /user=
            // when it has one, and only then with imap_open()'s argument.
            $spec->switches->user ?? $user,
            $password,
            $spec->switches->authuser,
            $spec->switches->anonymous || (bool) ($flags & OP_ANONYMOUS),
            $spec->switches->secure || (bool) ($flags & OP_SECURE),
        );

        $isPop3 = $spec->service === Service::Pop3;
        $backend = $isPop3
            ? self::connectPop3($spec, $mailbox, $credentials, $retries)
            : self::connectImap($spec, $credentials, $retries);

        if ($backend === false) {
            return false;
        }

        // c-client treats a /readonly flag in the spec the same as passing
        // OP_READONLY (mail_valid_net_parse sets the stream read-only bit).
        $readOnly = (bool) ($flags & OP_READONLY) || $spec->switches->readOnly;
        $connection = new \IMAP\Connection(
            $backend,
            $spec->folder,
            $spec->normalizedPrefixBase($credentials->secure, $spec->switches->tls),
            $credentials->user,
            $readOnly,
            $credentials->password,
            $credentials->anonymous,
        );
        $connection->setExpungeOnClose((bool) ($flags & CL_EXPUNGE));

        if ($flags & OP_HALFOPEN) {
            $connection->markHalfOpen();
        }

        try {
            $status = $connection->selectOrExamine();
            $connection->rememberCounts($status->exists, $status->recent);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return $connection;
    }

    public static function connectImap(MailboxSpec $spec, Credentials $credentials, int $retries): \ImapPolyfill\Connection\ConnectionBackend|false
    {
        $attempts = 1 + max(0, $retries);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $connection = new \ImapPolyfill\Connection\Imap\ImapEngineConnection(
                new \ImapPolyfill\Connection\Imap\TimedStream()
            );

            try {
                $connection->connect($spec->host, $spec->port, [
                    'encryption' => $spec->encryption(),
                    'validate_cert' => !$spec->switches->novalidate,
                    'timeout' => Timeouts::seconds(IMAP_OPENTIMEOUT),
                ]);

                if ($credentials->anonymous) {
                    // c-client offers the client's own host name as the
                    // anonymous contact address (net_localhost).
                    $connection->loginAnonymous(gethostname() ?: 'localhost');
                } else {
                    $credentials->assertPlaintextLoginAllowed($connection->capabilities());
                    $connection->login($credentials->user, $credentials->password);
                }

                // c-client clears its capability cache across authentication:
                // a server routinely advertises more to a logged-in client
                // than it did to a stranger, and the gated commands
                // (SORT, THREAD, QUOTA) read the second answer.
                $connection->forgetCapabilities();

                return new \ImapPolyfill\Connection\Imap\ImapBackend($connection, $spec->host);
            } catch (\DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException $e) {
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

    public static function connectPop3(MailboxSpec $spec, string $mailbox, Credentials $credentials, int $retries): \ImapPolyfill\Connection\ConnectionBackend|false
    {
        // pop3.c refuses before it dials: the protocol has no anonymous
        // access to offer, so there is nothing to try.
        if ($credentials->anonymous) {
            ErrorStack::push('Anonymous POP3 login not available');

            return false;
        }

        $attempts = 1 + max(0, $retries);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $protocol = new \ImapPolyfill\Connection\Pop3\Pop3Protocol();

            try {
                $protocol->connect(
                    $spec->host,
                    $spec->port,
                    $spec->encryption(),
                    !$spec->switches->novalidate,
                    (float) Timeouts::seconds(IMAP_OPENTIMEOUT),
                    (float) Timeouts::seconds(IMAP_READTIMEOUT),
                );

                // No CAPA: pop3_auth() checks these before looking at what
                // the server offers, and this backend has only USER/PASS.
                $credentials->assertPlaintextLoginAllowed();
                $protocol->login($credentials->user, $credentials->password);

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
            $this->connection->backend()->expunge();
        }

        $this->connection->backend()->disconnect();
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

        $this->connection->rememberCounts($status->exists, $status->recent);

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

        $this->connection->rememberCounts($status->exists, $status->recent);

        return $this->connection->numRecent();
    }

    public function check(): \stdClass|false
    {
        $this->connection->ensureOpen();

        // php_imap.c pings the stream before asking it anything, and
        // answers false when that fails. Noticing a dead stream is the
        // ping's answer rather than an error, so nothing is recorded.
        if (!$this->ping()) {
            return false;
        }

        try {
            // A live query, unlike the cached counters imap_num_msg() reads:
            // CHECK makes the server report the folder's current state.
            $this->connection->check();
            $status = $this->connection->selectOrExamine();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $result = new \stdClass();
        $result->Date = date('r');
        $result->Driver = $this->connection->backend()->driverName();
        $result->Mailbox = $this->connection->mailboxString();
        $result->Nmsgs = $status->exists;
        $result->Recent = $status->recent;

        return $result;
    }

    public function mailboxMsgInfo(): \stdClass|false
    {
        $this->connection->ensureOpen();

        try {
            $status = $this->connection->selectOrExamine();
            $exists = $status->exists;

            $unread = 0;
            $deleted = 0;
            $size = 0;
            if ($exists > 0) {
                $data = $this->connection->backend()->fetch(
                    ['FLAGS', 'RFC822.SIZE'],
                    range(1, $exists),
                    null,
                    UidMode::MSGNO,
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
        $result->Driver = $this->connection->backend()->driverName();
        $result->Mailbox = $this->connection->mailboxString();
        $result->Nmsgs = $exists;
        $result->Recent = $status->recent;

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
            $this->connection->backend()->noop();
        } catch (\DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException) {
            // Reporting a dead stream is what this function is for, so
            // finding one is an answer rather than a failure: c-client
            // returns NIL and logs nothing.
            return false;
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
        $readOnly = (bool) ($flags & OP_READONLY) || $spec->switches->readOnly;
        $isPop3 = $spec->service === Service::Pop3;

        if ($spec->service === Service::Nntp) {
            throw \ImapPolyfill\Support\UnsupportedFeature::nntp($mailbox);
        }

        // Naming another server reopens against it, the way c-client's
        // mail_open() does on an existing stream; the credentials come from
        // the ones imap_open() kept, as php_imap.c answers mm_login() with —
        // except for a /user= in this spec, which mm_login() prefers.
        $credentials = new Credentials(
            $spec->switches->user ?? $this->connection->user(),
            $this->connection->password(),
            $spec->switches->authuser,
            $spec->switches->anonymous || (bool) ($flags & OP_ANONYMOUS),
            $spec->switches->secure,
        );
        $prefix = $spec->normalizedPrefixBase($credentials->secure, $spec->switches->tls);

        // A different login is a different session, whatever the host:
        // mail_usable_network_stream() will not recycle a stream across one.
        if ($prefix !== $this->connection->mailboxPrefix() || $credentials->user !== $this->connection->user()) {
            $status = $this->redial($spec, $mailbox, $isPop3, $prefix, $credentials, $readOnly, $retries);

            if ($status === false) {
                return false;
            }

            $this->connection->rememberCounts($status->exists, $status->recent);

            if ($flags !== 0) {
                $this->connection->setExpungeOnClose((bool) ($flags & CL_EXPUNGE));
            }

            return true;
        }

        try {
            $status = $this->connection->selectOrExamineFolder($spec->folder, $readOnly);
        } catch (\DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException) {
            // Losing the socket is not losing the connection: c-client
            // dials the host again and logs back in rather than reporting
            // a dead stream, which is what makes a session survive a server
            // that hangs up on it. Same host as before — the prefix matched.
            $status = $this->redial($spec, $mailbox, $isPop3, $prefix, $credentials, $readOnly, $retries);

            if ($status === false) {
                return false;
            }
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $this->connection->reselect($spec->folder, $readOnly);
        $this->connection->rememberCounts($status->exists, $status->recent);

        // Mirrors php_imap.c: a nonzero $flags overrides the remembered
        // CL_EXPUNGE state outright (even clearing it if CL_EXPUNGE isn't in
        // the new bitmask); $flags === 0 leaves whatever imap_open() set.
        if ($flags !== 0) {
            $this->connection->setExpungeOnClose((bool) ($flags & CL_EXPUNGE));
        }

        return true;
    }

    /**
     * Opens a second connection from the credentials imap_open() kept, hands
     * it to the existing IMAP\Connection in place of the one it holds, and
     * selects the spec's folder on it — the whole of what c-client's
     * mail_open() does to a stream it is reopening elsewhere, and to one
     * whose socket is gone.
     */
    private function redial(MailboxSpec $spec, string $mailbox, bool $isPop3, string $prefix, Credentials $credentials, bool $readOnly, int $retries): FolderState|false
    {
        $backend = $isPop3
            ? self::connectPop3($spec, $mailbox, $credentials, $retries)
            : self::connectImap($spec, $credentials, $retries);

        if ($backend === false) {
            return false;
        }

        $this->connection->reconnect($backend, $prefix, $credentials->user, $spec->folder, $readOnly, $credentials->anonymous);

        try {
            return $this->connection->selectOrExamine();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }
    }
}
