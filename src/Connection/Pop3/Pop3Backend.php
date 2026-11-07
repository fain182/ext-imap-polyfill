<?php

namespace ImapPolyfill\Connection\Pop3;

use ImapPolyfill\Connection\ConnectionBackend;
use ImapPolyfill\Connection\FolderState;
use ImapPolyfill\Connection\MessageCache;
use ImapPolyfill\Connection\UidTable;
use ImapPolyfill\Connection\UidMode;
use ImapPolyfill\Message\MessageSequence;
use ImapPolyfill\Message\SearchProgram;
use ImapPolyfill\Connection\MessageNotFoundException;

/**
 * ConnectionBackend implementation speaking raw POP3. Mirrors the real
 * ext-imap's treatment of POP3 (verified against genuine ext-imap in
 * tests/Integration/Pop3ParityCharacterizationTest.php): a single mailbox
 * named INBOX; SEARCH, flags, and BODYSTRUCTURE are all synthesized
 * client-side since POP3 has no wire equivalent; flags exist only for the
 * lifetime of this connection (POP3 has no persistent flag storage); COPY,
 * MOVE, APPEND, and mailbox-hierarchy mutation are all rejected outright.
 */
final class Pop3Backend implements ConnectionBackend
{
    private const UID_MODE = UidMode::Uid;

    /** Fake INTERNALDATE: POP3 has none, and real ext-imap reports the epoch. */
    private const FAKE_INTERNAL_DATE = ' 1-Jan-1970 00:00:00 +0000';

    /**
     * The session's messages, filled from STAT once and then edited only by
     * expunge(): a POP3 server keeps its own numbering for the whole session,
     * DELE or not, so nothing it reports later could renumber them.
     *
     * c-client's POP3 driver uses the server's message number as the uid; it
     * never reads the server's UIDL string. Taking the UIDL instead looks
     * equivalent against a server whose UIDL happens to be the message number
     * (Greenmail answers "1 1", "2 2"), and falls apart against one whose UIDL
     * is an opaque token: Dovecot's "000000016a759b25" cast to int is 16, so
     * imap_uid() invented a number and imap_msgno() could not find it again.
     * Once an expunge has renumbered the messages, the uid is also the number
     * every command to the server still has to name the message by.
     */
    private readonly MessageCache $messages;

    /** pop3.c's uid_last: the session's highest server number, which an expunge does not lower. */
    private int $uidLast = 0;

    /** @var array<int, string[]> uid => IMAP flag names set on it, session-scoped */
    private array $flags = [];

    /** @var array<int, RawMessage> uid => RETR answer, for the lifetime of the connection */
    private array $rawMessageCache = [];

    public function __construct(
        private readonly Pop3Protocol $protocol,
        private readonly string $host,
        private readonly string $mailboxSpec,
    ) {
        $this->messages = new MessageCache();
    }

    public function driverName(): string
    {
        return 'pop3';
    }

    public function upgradedToTls(): bool
    {
        return $this->protocol->upgradedToTls();
    }

    /**
     * POP3 has one mailbox and no SELECT, so there is no cached selection
     * to bypass: re-opening it is opening it.
     */
    public function reselectFolder(string $folder, bool $readOnly): FolderState
    {
        return $this->selectOrExamineFolder($folder, $readOnly);
    }

    public function selectOrExamineFolder(string $folder, bool $readOnly): FolderState
    {
        if ($readOnly) {
            throw new \RuntimeException('Read-only POP3 access not available');
        }

        if (strcasecmp($folder, 'INBOX') !== 0) {
            throw new \RuntimeException("Can't open mailbox {$this->mailboxSpec}: invalid remote specification");
        }

        if ($this->messages->count() === null) {
            $count = $this->protocol->stat();
            $this->uidLast = $count;
            $this->messages->exists($count);

            for ($msgno = 1; $msgno <= $count; $msgno++) {
                $this->messages->learnUid($msgno, $msgno);
            }
        }

        $count = (int) $this->messages->count();

        // POP3 has no concept of a message having been seen in a previous
        // session; every message the server still has is "recent" (matches
        // real ext-imap's observed imap_status()/imap_check() output).
        return new FolderState($count, $count);
    }

    public function host(): string
    {
        return $this->host;
    }

    /**
     * DELE goes out here and not when \Deleted is set, as pop3_expunge()
     * sends it: until then a deleted message is still there to read, and a
     * session closed without expunging deletes nothing at all.
     */
    public function expunge(): void
    {
        foreach (array_reverse($this->messages->uidTable()->byMsgno(), true) as $msgno => $uid) {
            if (in_array('\\Deleted', $this->flags[$uid] ?? [], true)) {
                $this->protocol->dele($uid);
                $this->messages->expunged($msgno);
            }
        }
    }

    public function disconnect(): void
    {
        $this->protocol->quit();
    }

    public function createFolder(string $name): void
    {
        throw new \RuntimeException('Mailbox is empty');
    }

    public function deleteFolder(string $name): void
    {
        throw new \RuntimeException("Can't delete mailbox {$name}: no such mailbox");
    }

    public function renameFolder(string $from, string $to): void
    {
        throw new \RuntimeException("Can't delete mailbox {$from}: no such mailbox");
    }

    public function subscribeFolder(string $name): void
    {
        // POP3 has no subscription state; real ext-imap silently no-ops.
    }

    public function unsubscribeFolder(string $name): void
    {
        // See subscribeFolder().
    }

    public function appendMessage(string $folder, string $message, ?array $flags, ?string $internalDate): void
    {
        throw new \RuntimeException('Append not valid for POP3');
    }

    public function fetchBodyStructure(int $messageNum, bool $byUid): array
    {
        $uid = $byUid ? $this->knownUid($messageNum) : $this->messages->uidTable()->uidOf($messageNum);

        return Pop3MimeStructure::parse($this->rawMessage($uid));
    }

    public function search(SearchProgram $program, UidMode $uidMode, string $charset = ''): array
    {
        $ids = [];
        foreach ($this->messages->uidTable()->byMsgno() as $msgno => $uid) {
            if (Pop3SearchEvaluator::matches($program, $this->rawMessage($uid), $this->flags[$uid] ?? [])) {
                $ids[] = $uidMode === self::UID_MODE ? $uid : $msgno;
            }
        }

        return $ids;
    }

    /**
     * POP3 advertises nothing an imap_* function gates on, so every
     * capability-gated code path takes its non-IMAP branch.
     */
    public function hasCapability(string $capability): bool
    {
        return false;
    }

    /**
     * POP3 has no SORT command; null hands imap_sort() back to its local sort.
     */
    public function sort(string $program, string $charset, array $searchTokens, UidMode $uidMode): ?array
    {
        return null;
    }

    /**
     * Likewise no THREAD; imap_thread() threads locally over POP3.
     */
    public function thread(string $algorithm, string $charset, array $searchTokens, UidMode $uidMode): ?array
    {
        return null;
    }

    public function fetch(array $items, array $ids, ?int $to, UidMode $uidMode): array
    {
        $result = [];
        foreach ($ids as $id) {
            $uid = $uidMode === self::UID_MODE ? $this->knownUid($id) : $this->messages->uidTable()->uidOf($id);
            $message = $this->rawMessage($uid);

            $entry = [];
            foreach ($items as $item) {
                $entry[$item] = $this->fetchItem($item, $uid, $message);
            }

            // Mirrors the IMAP backend's fetch() response shape: a single
            // requested item collapses to its scalar value per id instead
            // of a one-key array (see Mailbox::body()/fetchBody()/fetchMime()
            // vs. headerInfo()/fetchOverview(), which read it back that way).
            $result[$id] = count($items) === 1 ? reset($entry) : $entry;
        }

        return $result;
    }

    private function fetchItem(string $item, int $uid, RawMessage $message): mixed
    {
        return match (true) {
            // pop3_open() marks every message of the session recent.
            $item === 'FLAGS' => ['\\Recent', ...($this->flags[$uid] ?? [])],
            $item === 'INTERNALDATE' => self::FAKE_INTERNAL_DATE,
            $item === 'RFC822.SIZE' => (string) strlen($message->getRaw()),
            $item === 'RFC822.HEADER' => $message->getRawHeader()."\r\n",
            $item === 'UID' => (string) $uid,
            str_starts_with($item, 'BODY.PEEK[') || str_starts_with($item, 'BODY[') => $this->fetchSection($item, $message),
            // Not a condition a caller can be in: every item the polyfill
            // asks for has an arm above. Answering '' for the rest would
            // make a new one look answered — which is how a raw
            // BODYSTRUCTURE fetch went out and came back empty here while
            // fetchBodyStructure(), which POP3 does implement, sat unused.
            default => throw new \LogicException(
                "Pop3Backend was asked for the FETCH item {$item}, which it does not answer. "
                .'Reach it through the named operation on ConnectionBackend instead.'
            ),
        };
    }

    private function fetchSection(string $item, RawMessage $message): string
    {
        $section = substr($item, strpos($item, '[') + 1, -1);

        return match (true) {
            $section === 'TEXT' => $message->getBody(),
            $section === 'HEADER' => $message->getRawHeader()."\r\n",
            str_ends_with($section, '.MIME') => $message->getRawHeader()."\r\n",
            default => $message->getBody(),
        };
    }

    public function uidTable(): UidTable
    {
        return $this->messages->uidTable();
    }

    public function store(string $command, array $args): void
    {
        [$sequence, $action, $flagsAtom] = $args;
        $flagNames = array_filter(explode(' ', trim($flagsAtom, '()')));
        $adding = str_starts_with($action, '+');
        $byUid = str_starts_with($command, 'UID');

        $set = MessageSequence::parse($sequence);
        $ids = $byUid
            ? $set->uids($this->messages->uidTable())
            : $set->messageNumbers((int) $this->messages->count());

        foreach ($ids as $id) {
            $uid = $byUid ? $id : $this->messages->uidTable()->uidOf($id);
            $current = $this->flags[$uid] ?? [];

            foreach ($flagNames as $flag) {
                $current = $adding
                    ? array_unique([...$current, $flag])
                    : array_values(array_diff($current, [$flag]));
            }

            $this->flags[$uid] = $current;
        }
    }

    public function folders(string $reference, string $pattern): array
    {
        return ['INBOX' => ['delimiter' => '.', 'flags' => []]];
    }

    public function subscribedFolders(string $reference, string $pattern): array
    {
        return $this->folders($reference, $pattern);
    }

    public function copy(string $sequence, string $folder, UidMode $uidMode): void
    {
        throw new \RuntimeException('Copy not valid for POP3');
    }

    /**
     * POP3 has no CHECK, and nothing a server says during a session can
     * change what the session holds, so there is no count to bring up to date.
     */
    public function check(): void
    {
        $this->protocol->noop();
    }

    public function noop(): void
    {
        $this->protocol->noop();
    }

    public function folderStatus(string $folder, array $items): array
    {
        $unseen = 0;
        foreach ($this->messages->uidTable()->uids() as $uid) {
            if (!in_array('\\Seen', $this->flags[$uid] ?? [], true)) {
                $unseen++;
            }
        }

        $count = (int) $this->messages->count();

        return [
            'messages' => $count,
            'recent' => $count,
            'unseen' => $unseen,
            'uidnext' => $this->uidLast + 1,
            'uidvalidity' => 1,
        ];
    }

    public function getAcl(string $mailbox): array
    {
        throw new \RuntimeException('ACL not available on this IMAP server');
    }

    public function setAcl(string $mailbox, string $id, string $rights): void
    {
        throw new \RuntimeException('ACL not available on this IMAP server');
    }

    public function getQuota(string $quotaRoot): array
    {
        throw new \RuntimeException('Quota not available on this IMAP server');
    }

    public function getQuotaRoot(string $mailbox): array
    {
        throw new \RuntimeException('Quota not available on this IMAP server');
    }

    public function setQuota(string $quotaRoot, int $mailboxSize): void
    {
        throw new \RuntimeException('Quota not available on this IMAP server');
    }

    private function rawMessage(int $uid): RawMessage
    {
        return $this->rawMessageCache[$uid] ??= new RawMessage($this->protocol->retr($uid));
    }

    /**
     * @throws MessageNotFoundException
     */
    private function knownUid(int $uid): int
    {
        if (!$this->messages->uidTable()->holds($uid)) {
            throw new MessageNotFoundException("Message with uid {$uid} not found");
        }

        return $uid;
    }
}
