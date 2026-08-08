<?php

namespace IMAP;

use ImapPolyfill\Connection\ConnectionBackend;
use ImapPolyfill\Connection\FolderState;

/**
 * Polyfill for the opaque IMAP\Connection class ext-imap registers natively.
 * Holds connection-level state (selected folder, read-only flag, cached
 * counters) and delegates every wire operation to a ConnectionBackend —
 * either Connection\Imap\ImapBackend (IMAP) or Connection\Pop3\Pop3Backend
 * (POP3), chosen by Session::open() from the mailbox spec. Knows nothing
 * about imap_* contracts, ErrorStack, or return-value conventions.
 */
final class Connection
{
    private ConnectionBackend $backend;

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

    private bool $halfOpen = false;

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
        private string $mailboxPrefix,
        private string $user,
        bool $readOnly = false,
        /**
         * Kept so imap_reopen() can reach a different server, which is what
         * the real extension does: c-client asks php_imap for credentials
         * again through mm_login(), and php_imap answers from the ones
         * imap_open() stored for the request.
         */
        private string $password = '',
    ) {
        $this->backend = $backend;
        $this->folder = $folder;
        $this->readOnly = $readOnly;
    }

    public function user(): string
    {
        return $this->user;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function mailboxPrefix(): string
    {
        return $this->mailboxPrefix;
    }

    /**
     * Swaps in a connection to somewhere else, for an imap_reopen() whose
     * spec names another server. The old one is closed first: c-client
     * reuses the stream, so only one connection is ever live.
     */
    public function reconnect(ConnectionBackend $backend, string $mailboxPrefix, string $user, string $folder, bool $readOnly): void
    {
        try {
            $this->backend->disconnect();
        } catch (\Throwable) {
            // The old connection is being discarded either way.
        }

        $this->backend = $backend;
        $this->mailboxPrefix = $mailboxPrefix;
        $this->user = $user;
        $this->folder = $folder;
        $this->readOnly = $readOnly;
        $this->halfOpen = false;
        $this->userFlags = [];
    }

    /**
     * The c-client-normalized spec string reported by imap_check()/
     * imap_mailboxmsginfo(): the connection prefix (see
     * MailboxSpec::normalizedPrefixBase) plus the state-dependent pieces —
     * read-only marker, user, and the *currently selected* folder, so it
     * tracks imap_reopen() like stream->mailbox does.
     */
    public function mailboxString(): string
    {
        return $this->mailboxPrefix
            .($this->readOnly ? '/readonly' : '')
            .'/user="'.$this->user.'"}'
            .($this->halfOpen ? '<no_mailbox>' : $this->folder);
    }

    /**
     * OP_HALFOPEN: connected and authenticated, with nothing selected and
     * no folder to select later — c-client leaves stream->mailbox empty and
     * php_imap prints the literal "<no_mailbox>" in its place. The spec's
     * folder is remembered all the same, so an imap_reopen() naming it
     * lands where it would have.
     */
    public function markHalfOpen(): void
    {
        $this->halfOpen = true;
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
        $this->halfOpen = false;
    }

    public function check(): void
    {
        // Nothing is selected for the server to report on.
        if ($this->halfOpen) {
            return;
        }

        $this->backend->check();
    }

    /**
     * Makes sure the current folder is the selected one before an operation,
     * the way every wrapper function needs. Uses EXAMINE instead of SELECT
     * when the connection was opened with OP_READONLY, so a read-only
     * imap_open() doesn't get silently escalated back to read-write on the
     * next call — matching ext-imap, the read-only guarantee itself is
     * enforced by the IMAP server rejecting writes, not by this client.
     *
     * Only actually selects when the selection changed: the counts it returns
     * otherwise come from the untagged responses the backend tracks, which is
     * how c-client reports them too.
     */
    public function selectOrExamine(): FolderState
    {
        // A half-open connection has no selection to make or restore, and
        // an empty folder is what every caller of this reads as "nothing
        // there" — the 0 messages imap_num_msg() reports on one.
        //
        // Divergence: an operation that then goes to the wire anyway, as
        // imap_search() does, gets the server's "command not valid in this
        // state" onto the error stack. c-client answers those from
        // stream->halfopen without asking, so its stack stays empty. The
        // return values match either way.
        if ($this->halfOpen) {
            return new FolderState(0, 0);
        }

        return $this->selectOrExamineFolder($this->folder, $this->readOnly);
    }

    /**
     * Selects/examines a folder other than the currently-remembered one,
     * e.g. to probe a target folder before imap_reopen() commits to it via
     * reselect(). Does not touch $this->folder/$this->readOnly itself.
     */
    public function selectOrExamineFolder(string $folder, bool $readOnly): FolderState
    {
        $state = $this->backend->selectOrExamineFolder($folder, $readOnly);

        $this->registerUserFlags($state->flags);

        return $state;
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
     * The protocol driver, reached directly for every wire operation: it is
     * the whole of what this connection can say, the way c-client's callers
     * go through stream->dtb.
     *
     * A method exists on this class instead only when it reads or writes
     * this connection's own state — check() and selectOrExamine() say
     * nothing to a half-open connection, selectOrExamineFolder() registers
     * the keywords the SELECT reported. Anything that would just forward
     * to the driver unchanged belongs on the driver, where the callers can
     * see the one contract that governs it.
     */
    public function backend(): ConnectionBackend
    {
        return $this->backend;
    }
}
