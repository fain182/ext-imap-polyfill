<?php

namespace ImapPolyfill\Session;

use ImapPolyfill\Connection\Backends;
use ImapPolyfill\Connection\ConnectionBackend;
use ImapPolyfill\Connection\ConnectionClosedException;
use ImapPolyfill\Connection\Credentials;
use ImapPolyfill\Connection\FolderState;
use ImapPolyfill\Connection\LoginRefused;
use ImapPolyfill\Connection\LoginTrials;
use ImapPolyfill\Connection\UidMode;
use ImapPolyfill\Mailbox\MailboxSpec;
use ImapPolyfill\Mailbox\Service;
use ImapPolyfill\Support\ErrorStack;

/**
 * Lifecycle of an \IMAP\Connection: opening it, closing it, reopening it
 * onto a different folder, and the cached count/status reads that go with
 * it. Operations on the selected mailbox's messages live in Mailbox, and
 * folder-hierarchy operations in MailboxHierarchy.
 */
final class Session
{
    /**
     * c-client's rfc822_date(), which imap_check() and imap_mailboxmsginfo()
     * report as Date. It reads the zone from libc's TZ; this reads PHP's.
     */
    private const RFC822_DATE = 'D, j M Y H:i:s O (T)';

    public function __construct(private readonly \IMAP\Connection $connection)
    {
    }

    /**
     * The body of imap_open(): builds a connection from the mailbox spec,
     * connects (a nonzero $retries setting how many logins it tries; see
     * LoginTrials), and selects the spec's
     * folder. Returns false instead of throwing on any failure — bad spec,
     * unreachable host, missing folder — pushing the cause onto the
     * ErrorStack; the shim in functions.php only adds the user-facing warning.
     */
    /**
     * @param array<array-key, mixed> $options
     */
    public static function open(string $mailbox, string $user, string $password, int $flags, int $retries, array $options = []): \IMAP\Connection|false
    {
        if ($flags && ($flags & ~(OP_READONLY | OP_ANONYMOUS | OP_HALFOPEN | CL_EXPUNGE | OP_DEBUG
                | OP_SHORTCACHE | OP_SILENT | OP_PROTOTYPE | OP_SECURE)) !== 0) {
            throw new \ValueError('imap_open(): Argument #4 ($flags) must be a bitmask of the OP_* constants, and CL_EXPUNGE');
        }

        if ($retries < 0) {
            throw new \ValueError('imap_open(): Argument #5 ($retries) must be greater than or equal to 0');
        }

        self::validateOptions($options);

        if ($retries > 0) {
            LoginTrials::limitTo($retries);
        }

        try {
            $spec = MailboxSpec::parse($mailbox);
        } catch (\ValueError $e) {
            // OP_SILENT reaches mail_valid() as a null purpose string, and a
            // null purpose is what suppresses the message. Only this one:
            // the errors the driver logs once it is dialing are unaffected,
            // and so is the false return.
            if (!($flags & OP_SILENT)) {
                ErrorStack::push($e->getMessage());
            }

            return false;
        }

        // Refused rather than quietly opened over IMAP instead.
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

        // mail_open_work() turns OP_HALFOPEN down for a driver without
        // DR_HALFOPEN, pop3 among them, before it dials and without logging.
        if ($isPop3 && ($flags & OP_HALFOPEN)) {
            return false;
        }

        $backend = $isPop3
            ? self::connectPop3($spec, $mailbox, $credentials)
            : self::connectImap($spec, $credentials);

        if ($backend === false) {
            return false;
        }

        // c-client treats a /readonly flag in the spec the same as passing
        // OP_READONLY (mail_valid_net_parse sets the stream read-only bit).
        $readOnly = (bool) ($flags & OP_READONLY) || $spec->switches->readOnly;
        $connection = \IMAP\Connection::forSession(
            $backend,
            $spec->folder,
            $spec->normalizedPrefixBase($credentials->secure, $backend->upgradedToTls()),
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
            $connection->selectOrExamine();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            // A mailbox that cannot be selected does not cost the caller
            // the stream: c-client's mail_open keeps the one it has just
            // logged into and leaves it half-open, reporting <no_mailbox>
            // until an imap_reopen() names a folder it can select. Only the
            // dialling and the login answer imap_open() with false, and both
            // of those fail before there is anything to keep.
            //
            // Nothing to keep over POP3 either: half-open is a state of an
            // IMAP stream, which exists whether or not a mailbox is
            // selected. A POP3 mailbox is the session, so pop3.c refusing it
            // — read-only access, or any name but INBOX — is mail_open
            // returning NIL.
            if ($spec->service === Service::Pop3) {
                return false;
            }

            $connection->markHalfOpen();
        }

        return $connection;
    }

    /**
     * php_imap.c reads one option, DISABLE_AUTHENTICATOR, and hands each
     * name to c-client, which stops using that SASL mechanism for every
     * later open in the process. This package has no SASL mechanisms (it
     * logs in with LOGIN, or USER/PASS over POP3, neither of which is an
     * authenticator), so the names have nothing to act on here. What
     * remains is the type check, which the extension makes before dialling.
     *
     * The array is walked by position, 0 to count-1, as php_imap.c walks it:
     * an entry under any other key is never looked at.
     *
     * @param array<array-key, mixed> $options
     */
    private static function validateOptions(array $options): void
    {
        if (!array_key_exists('DISABLE_AUTHENTICATOR', $options)) {
            return;
        }

        $disabled = $options['DISABLE_AUTHENTICATOR'];
        $refusal = 'imap_open(): Argument #6 ($options) option "DISABLE_AUTHENTICATOR" must be a string or an array of strings';

        if (is_string($disabled)) {
            return;
        }

        if (!is_array($disabled)) {
            throw new \TypeError($refusal);
        }

        for ($i = 0, $count = count($disabled); $i < $count; $i++) {
            if (array_key_exists($i, $disabled) && !is_string($disabled[$i])) {
                throw new \TypeError($refusal);
            }
        }
    }

    public static function connectImap(MailboxSpec $spec, Credentials $credentials): ConnectionBackend|false
    {
        try {
            return Backends::imap($spec, $credentials);
        } catch (\Throwable $e) {
            self::reportFailedConnection($e);

            return false;
        }
    }

    public static function connectPop3(MailboxSpec $spec, string $mailbox, Credentials $credentials): ConnectionBackend|false
    {
        // pop3.c refuses before it dials: the protocol has no anonymous
        // access to offer, so there is nothing to try.
        if ($credentials->anonymous) {
            ErrorStack::push('Anonymous POP3 login not available');

            return false;
        }

        try {
            return Backends::pop3($spec, $mailbox, $credentials);
        } catch (\Throwable $e) {
            self::reportFailedConnection($e);

            return false;
        }
    }

    private static function reportFailedConnection(\Throwable $e): void
    {
        foreach ($e instanceof LoginRefused ? $e->messages() : [$e->getMessage()] as $message) {
            ErrorStack::push($message);
        }
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

        return $this->counts()->exists;
    }

    public function numRecent(): int|false
    {
        $this->connection->ensureOpen();

        return $this->counts()->recent;
    }

    /**
     * ext-imap's imap_num_msg/imap_num_recent are client-side reads
     * (c-client's stream->nmsgs, stream->recent), not live queries: the
     * message cache answers them without the wire once the folder is
     * selected, so a connection that has since broken leaves the count it
     * last reported standing. Only a selection that cannot be made at all
     * is a failure to report, and then there is no mailbox to count.
     */
    private function counts(): FolderState
    {
        try {
            return $this->connection->selectOrExamine();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return new FolderState(0, 0);
        }
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
        $result->Date = date(self::RFC822_DATE);
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
                    UidMode::Msgno,
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
        $result->Date = date(self::RFC822_DATE);
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
        } catch (ConnectionClosedException) {
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

        if ($retries > 0) {
            LoginTrials::limitTo($retries);
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
        // pop3.c has no half-open state: the flag reaches the driver, which
        // never reads it, and the reopened session is the mailbox as usual.
        $halfOpen = (bool) ($flags & OP_HALFOPEN) && !$isPop3;

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
        // Compared against what this connection negotiated, not against what
        // the spec asked for: reopening the same host would upgrade the same
        // way, and reading /tls as a difference would redial every time.
        $prefix = $spec->normalizedPrefixBase($credentials->secure, $this->connection->backend()->upgradedToTls());

        // A different login is a different session, whatever the host:
        // mail_usable_network_stream() will not recycle a stream across one.
        // Nor does mail_open() recycle a POP3 stream at all, its driver
        // lacking DR_RECYCLE: the session is closed, committing the DELEs an
        // expunge sent, and a new one numbers the messages afresh with no
        // flags set.
        //
        // Nor can c-client leave a selection behind on a server without
        // UNSELECT: going half-open there logs out and dials again
        // (imap4r1.c, imap_open).
        if ($isPop3 || $prefix !== $this->connection->mailboxPrefix() || $credentials->user !== $this->connection->user()
            || ($halfOpen && !$this->connection->backend()->hasCapability('UNSELECT'))) {
            $status = $this->redial($spec, $mailbox, $isPop3, $credentials, $readOnly, $halfOpen);

            if ($status === false) {
                return false;
            }

            if ($flags !== 0) {
                $this->connection->setExpungeOnClose((bool) ($flags & CL_EXPUNGE));
            }

            return true;
        }

        try {
            if ($halfOpen) {
                $this->connection->unselect();
            } else {
                $this->connection->reselectFolder($spec->folder, $readOnly);
            }
        } catch (ConnectionClosedException) {
            // Losing the socket is not losing the connection: c-client
            // dials the host again and logs back in rather than reporting
            // a dead stream, which is what makes a session survive a server
            // that hangs up on it. Same host as before — the prefix matched.
            $status = $this->redial($spec, $mailbox, $isPop3, $credentials, $readOnly, $halfOpen);

            if ($status === false) {
                return false;
            }
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        // The folder is remembered even when nothing is selected, as
        // imap_open() remembers it, for a later imap_reopen() to land on.
        $this->connection->reselect($spec->folder, $readOnly);

        if ($halfOpen) {
            $this->connection->markHalfOpen();
        }

        // Mirrors php_imap.c: a nonzero $flags overrides the remembered
        // CL_EXPUNGE state outright (even clearing it if CL_EXPUNGE isn't in
        // the new bitmask); $flags === 0 leaves whatever imap_open() set.
        if ($flags !== 0) {
            $this->connection->setExpungeOnClose((bool) ($flags & CL_EXPUNGE));
        }

        return true;
    }

    /**
     * Ends the session the IMAP\Connection holds, opens another from the
     * credentials imap_open() kept, hands it over in place of the old one, and
     * selects the spec's folder on it — the whole of what c-client's
     * mail_open() does to a stream it is reopening elsewhere, and to one
     * whose socket is gone.
     */
    private function redial(MailboxSpec $spec, string $mailbox, bool $isPop3, Credentials $credentials, bool $readOnly, bool $halfOpen): FolderState|false
    {
        $this->connection->disconnect();

        $backend = $isPop3
            ? self::connectPop3($spec, $mailbox, $credentials)
            : self::connectImap($spec, $credentials);

        // mail_open() answers NIL with the old stream already gone, and
        // php_imap.c leaves the IMAP\Connection without one.
        if ($backend === false) {
            $this->connection->close();
            trigger_error("imap_reopen(): Couldn't re-open stream", E_USER_WARNING);

            return false;
        }

        $this->connection->reconnect(
            $backend,
            $spec->normalizedPrefixBase($credentials->secure, $backend->upgradedToTls()),
            $credentials->user,
            $spec->folder,
            $readOnly,
            $credentials->anonymous,
        );

        if ($halfOpen) {
            $this->connection->markHalfOpen();
        }

        try {
            return $this->connection->selectOrExamine();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }
    }
}
