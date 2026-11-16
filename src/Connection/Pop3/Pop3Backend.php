<?php

namespace ImapPolyfill\Connection\Pop3;

use ImapPolyfill\Connection\ConnectionBackend;
use ImapPolyfill\Connection\FolderState;
use ImapPolyfill\Connection\ListedFolder;
use ImapPolyfill\Connection\MessageCache;
use ImapPolyfill\Connection\UidTable;
use ImapPolyfill\Connection\UidMode;
use ImapPolyfill\Message\BodyPart;
use ImapPolyfill\Message\Envelope;
use ImapPolyfill\Message\MessageSequence;
use ImapPolyfill\Message\MessageSummary;
use ImapPolyfill\Message\SearchProgram;
use ImapPolyfill\Message\SortCriterion;
use ImapPolyfill\Message\SortKeys;
use ImapPolyfill\Connection\MessageNotFoundException;
use ImapPolyfill\Mailbox\MalformedSpec;

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

    /**
     * The one message last read whole, as pop3_cache() keeps LOCAL->txt and
     * LOCAL->cached: the next RETR replaces it. $structureCache still keeps
     * the text of every message it parsed, inside its MimeBody.
     */
    private ?RawMessage $lastRetr = null;

    /** pop3_cache()'s LOCAL->cached: the uid $lastRetr belongs to, 0 for none */
    private int $lastRetrUid = 0;

    /** @var array<int, RawMessage> uid => the header alone, from TOP n 0 or cut off a RETR */
    private array $headerCache = [];

    /** @var array<int, int> uid => the length of its RETR answer, for every message ever read whole */
    private array $retrSizes = [];

    /** @var array<int, MimeBody> uid => the RETR answer parsed */
    private array $structureCache = [];

    /** @var list<string>|null what CAPA answered after login; null for a server without it */
    private ?array $capabilities = null;

    private bool $capabilitiesRead = false;

    /** @var array<int, int>|null uid => the size LIST gave it, read on first need */
    private ?array $listedSizes = null;

    /**
     * @param bool $loser the spec's /loser: pop3.c then trusts neither LIST
     *                    nor TOP, and reads sizes and headers off RETR
     */
    public function __construct(
        private readonly Pop3Protocol $protocol,
        private readonly string $host,
        private readonly string $mailboxSpec,
        private readonly bool $loser,
    ) {
        $this->messages = new MessageCache();
    }

    public function driverName(): string
    {
        return 'pop3';
    }

    public function hasOverview(): bool
    {
        return false;
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

    /**
     * A POP3 session is its mailbox, so there is no selection to leave.
     * hasCapability() answers false for UNSELECT as for everything else, so
     * no caller reaches this.
     */
    public function unselect(): void
    {
        throw new \LogicException('POP3 has no selection to leave');
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

                if ($uid === $this->lastRetrUid) {
                    $this->lastRetr = null;
                    $this->lastRetrUid = 0;
                }
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

    public function fetchBodyStructure(int $messageNum, bool $byUid): BodyPart
    {
        $uid = $byUid ? $this->knownUid($messageNum) : $this->messages->uidTable()->uidOf($messageNum);

        return $this->structure($uid)->toBodyPart();
    }

    public function eachSummary(array $ids, UidMode $uidMode, \Closure $each): void
    {
        foreach ($this->fetch(['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'], $ids, null, $uidMode) as $id => $message) {
            $each($id, new MessageSummary(
                (int) $message['UID'],
                $message['FLAGS'],
                $message['INTERNALDATE'],
                (int) $message['RFC822.SIZE'],
                Envelope::fromHeader($message['RFC822.HEADER'], $this->host),
            ));
        }
    }

    public function sortKeys(array $msgnos, SortCriterion $criterion): array
    {
        $missing = $this->messages->withoutSortKeys($msgnos, $criterion);

        if ($missing !== []) {
            if ($criterion->needsEnvelope()) {
                $this->eachSummary($missing, UidMode::Msgno, function (int $msgno, MessageSummary $summary): void {
                    $this->messages->learnSortKeys(
                        $msgno,
                        SortKeys::withEnvelope($summary->envelope, $summary->internalDate, $summary->size),
                    );
                });
            } else {
                foreach ($this->fetch(['INTERNALDATE', 'RFC822.SIZE'], $missing, null, UidMode::Msgno) as $msgno => $message) {
                    $this->messages->learnSortKeys(
                        $msgno,
                        SortKeys::fast((string) $message['INTERNALDATE'], (int) $message['RFC822.SIZE']),
                    );
                }
            }
        }

        return $this->messages->sortKeys($msgnos);
    }

    public function search(SearchProgram $program, UidMode $uidMode, ?string $charset = null): array
    {
        // Checked before any message is read, as mail_search_default()
        // checks it before its loop.
        $charset = $charset === null ? null : SearchCharset::named($charset);

        $reads = [MessagePart::Header->name => [], MessagePart::Whole->name => []];
        foreach ($this->messages->uidTable()->uids() as $uid) {
            $reads[Pop3SearchEvaluator::reads($program, $this->flags[$uid] ?? [])->name][] = $uid;
        }
        $this->readAhead($reads[MessagePart::Whole->name], $reads[MessagePart::Header->name]);

        $ids = [];
        foreach ($this->messages->uidTable()->byMsgno() as $msgno => $uid) {
            if (Pop3SearchEvaluator::matches($program, fn (): RawMessage => $this->header($uid), fn (): MimeBody => $this->structure($uid), $this->flags[$uid] ?? [], $charset)) {
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
    public function sort(string $program, string $charset, ?SearchProgram $search, UidMode $uidMode): ?array
    {
        return null;
    }

    /**
     * Likewise no THREAD; imap_thread() threads locally over POP3.
     */
    public function thread(string $algorithm, string $charset, ?SearchProgram $search, UidMode $uidMode): ?array
    {
        return null;
    }

    public function fetch(array $items, array $ids, ?int $to, UidMode $uidMode): array
    {
        $uids = [];
        foreach ($ids as $id) {
            $uids[$id] = $uidMode === self::UID_MODE ? $this->knownUid($id) : $this->messages->uidTable()->uidOf($id);
        }

        $reads = array_map(self::partReadBy(...), $items);
        if (in_array(MessagePart::Whole, $reads, true)) {
            $this->readAhead(array_values($uids), []);
        } elseif (in_array(MessagePart::Header, $reads, true)) {
            $this->readAhead([], array_values($uids));
        }

        $result = [];
        foreach ($uids as $id => $uid) {
            $entry = [];
            foreach ($items as $item) {
                $entry[$item] = $this->fetchItem($item, $uid);
            }

            // Mirrors the IMAP backend's fetch() response shape: a single
            // requested item collapses to its scalar value per id instead
            // of a one-key array (see Mailbox::body()/fetchBody()/fetchMime()
            // vs. headerInfo()/fetchOverview(), which read it back that way).
            $result[$id] = count($items) === 1 ? reset($entry) : $entry;
        }

        return $result;
    }

    /**
     * What fetchItem() reads of the message to answer an item. RFC822.SIZE
     * reads the header because size() does, as pop3_fetchfast() does.
     */
    private static function partReadBy(string $item): MessagePart
    {
        return match (true) {
            in_array($item, ['FLAGS', 'INTERNALDATE', 'UID'], true) => MessagePart::None,
            in_array($item, ['RFC822.SIZE', 'RFC822.HEADER', 'BODY[HEADER]', 'BODY.PEEK[HEADER]'], true) => MessagePart::Header,
            str_starts_with($item, 'BODY.PEEK[') || str_starts_with($item, 'BODY[') => MessagePart::Whole,
            // fetchItem() refuses it, with the reason.
            default => MessagePart::None,
        };
    }

    private function fetchItem(string $item, int $uid): mixed
    {
        return match (true) {
            // pop3_open() marks every message of the session recent.
            $item === 'FLAGS' => ['\\Recent', ...($this->flags[$uid] ?? [])],
            $item === 'INTERNALDATE' => self::FAKE_INTERNAL_DATE,
            $item === 'RFC822.SIZE' => (string) $this->size($uid),
            $item === 'RFC822.HEADER' => $this->header($uid)->getRawHeader()."\r\n",
            $item === 'UID' => (string) $uid,
            str_starts_with($item, 'BODY.PEEK[') || str_starts_with($item, 'BODY[') => $this->fetchSection($item, $uid),
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

    /**
     * mail_fetch_body() and mail_fetch_mime() over the parsed message: a
     * section that names no body is an empty string, as c-client answers
     * it from the structure.
     */
    private function fetchSection(string $item, int $uid): string
    {
        $section = substr($item, strpos($item, '[') + 1, -1);

        if ($section === '') {
            return $this->rawMessage($uid)->getRaw();
        }

        if ($section === 'TEXT') {
            return $this->rawMessage($uid)->getBody();
        }

        if ($section === 'HEADER') {
            return $this->header($uid)->getRawHeader()."\r\n";
        }

        if (str_ends_with($section, '.MIME')) {
            return $this->structure($uid)->section(substr($section, 0, -5))?->mimeText() ?? '';
        }

        return $this->structure($uid)->section($section)?->text() ?? '';
    }

    public function uidTable(): UidTable
    {
        return $this->messages->uidTable();
    }

    public function uids(array $msgnos): array
    {
        $table = $this->messages->uidTable();
        $uids = [];
        foreach ($msgnos as $msgno) {
            $uids[$msgno] = $table->uidOf($msgno);
        }

        return $uids;
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

    /**
     * pop3_list(): the one mailbox there is, with no hierarchy delimiter
     * and no inferiors, listed only where the pattern matches "INBOX" and
     * the reference names no other mailbox (pop3_valid() takes nothing but
     * INBOX, with an empty name standing for it).
     */
    public function folders(string $reference, string $pattern): array
    {
        if (($reference !== '' && strcasecmp($reference, 'INBOX') !== 0) || !self::pmatch('INBOX', $pattern)) {
            return [];
        }

        return [new ListedFolder('INBOX', null, ['\\NoInferiors'])];
    }

    /**
     * pmatch() as pop3_list() calls it, with no delimiter: so "%" matches
     * across anything "*" does, and the comparison is case-sensitive, "inbox"
     * matching nothing.
     */
    private static function pmatch(string $subject, string $pattern): bool
    {
        if ($pattern === '') {
            return $subject === '';
        }

        if ($pattern[0] === '*' || $pattern[0] === '%') {
            for ($i = 0; $i <= strlen($subject); $i++) {
                if (self::pmatch(substr($subject, $i), substr($pattern, 1))) {
                    return true;
                }
            }

            return false;
        }

        return $subject !== '' && $subject[0] === $pattern[0] && self::pmatch(substr($subject, 1), substr($pattern, 1));
    }

    /**
     * pop3_lsub() reads c-client's local subscription file, which
     * subscribeFolder() never writes.
     */
    public function subscribedFolders(string $reference, string $pattern): array
    {
        return [];
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
        // pop3_valid(): no POP3 mailbox but INBOX exists to report on.
        if (strcasecmp($folder, 'INBOX') !== 0) {
            throw new MalformedSpec();
        }

        $unseen = 0;
        foreach ($this->messages->uidTable()->uids() as $uid) {
            if (!in_array('\\Seen', $this->flags[$uid] ?? [], true)) {
                $unseen++;
            }
        }

        $count = (int) $this->messages->count();

        $status = [
            'messages' => $count,
            'recent' => $count,
            'unseen' => $unseen,
            'uidnext' => $this->uidLast + 1,
            'uidvalidity' => 1,
        ];

        return array_intersect_key($status, array_flip(array_map(strtolower(...), $items)));
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

    /**
     * Reads ahead what rawMessage() and header() are about to ask for one
     * message at a time, as one pipelined batch, where the server advertises
     * PIPELINING. pop3.c never pipelines; this changes how many round trips
     * the answer takes and nothing about the answer, as each message is
     * cached exactly as the single command would have cached it. A message
     * the server refused is left for the single command to ask again, and
     * to fail where it would have failed.
     *
     * @param list<int> $whole   uids about to be read whole
     * @param list<int> $headers uids about to have their header read
     */
    private function readAhead(array $whole, array $headers): void
    {
        $whole = array_values(array_filter($whole, fn (int $uid): bool => !isset($this->rawMessageCache[$uid])));
        $headers = array_values(array_filter(
            $headers,
            fn (int $uid): bool => !isset($this->rawMessageCache[$uid]) && !isset($this->headerCache[$uid]),
        ));

        // /loser reads no CAPA at all.
        if ($this->loser || count($whole) + count($headers) < 2) {
            return;
        }

        $capabilities = $this->capabilities() ?? [];

        if (!in_array('PIPELINING', $capabilities, true)) {
            return;
        }

        if (in_array('TOP', $capabilities, true)) {
            foreach ($this->protocol->topEach($headers, 0) as $uid => $header) {
                $this->headerCache[$uid] = new RawMessage($header);
            }
        } else {
            $whole = [...$whole, ...$headers];
        }

        foreach ($this->protocol->retrEach($whole) as $uid => $message) {
            $this->rawMessageCache[$uid] = new RawMessage($message);
        }
    }

    private function rawMessage(int $uid): RawMessage
    {
        if ($this->lastRetr === null || $this->lastRetrUid !== $uid) {
            $this->lastRetr = new RawMessage($this->protocol->retr($uid));
            $this->lastRetrUid = $uid;
            $this->headerCache[$uid] ??= new RawMessage($this->lastRetr->getRawHeader());
            $this->retrSizes[$uid] = strlen($this->lastRetr->getRaw());
        }

        return $this->lastRetr;
    }

    /**
     * pop3_header(): TOP n 0 where the server advertised TOP, RETR where it
     * did not, each read once per message. A message already read whole
     * takes its header from that RETR, where pop3_header() would still send
     * TOP: pop3_cache() does not fill the header text.
     */
    private function header(int $uid): RawMessage
    {
        if (!isset($this->headerCache[$uid])) {
            if (!$this->loser && in_array('TOP', $this->capabilities() ?? [], true)) {
                $this->headerCache[$uid] = new RawMessage($this->protocol->top($uid, 0));
            } else {
                $this->rawMessage($uid);
            }
        }

        return $this->headerCache[$uid];
    }

    /**
     * The size LIST gave the message, where pop3_open() would have read one;
     * the length of the RETR answer otherwise. That length also replaces
     * LIST's figure once the message has been read whole, as pop3_cache()
     * writes it over rfc822_size: a server's LIST is free to disagree with
     * the octets it sends (Dovecot's leaves out the CRLF it adds to a
     * message that lacks a final one).
     *
     * pop3_fetchfast() reads the header before it looks at the size, so on
     * a server without TOP the header's RETR has already replaced LIST's
     * figure by then: LIST only ever shows through where TOP is advertised.
     */
    private function size(int $uid): int
    {
        $this->header($uid);

        if (!isset($this->retrSizes[$uid])) {
            if (($listed = $this->listedSizes()[$uid] ?? 0) > 0) {
                return $listed;
            }

            $this->rawMessage($uid);
        }

        return $this->retrSizes[$uid];
    }

    /**
     * pop3_open() sends LIST once, at open, and only to a server that
     * answered CAPA: an older one's LIST was not to be trusted. It is read
     * here on first need instead, which no answer can tell apart, as a POP3
     * server keeps the session's sizes fixed.
     *
     * @return array<int, int>
     */
    private function listedSizes(): array
    {
        return $this->listedSizes ??= !$this->loser && $this->capabilities() !== null ? $this->protocol->list() : [];
    }

    /**
     * pop3_auth() reads CAPA again once logged in, and that answer is the
     * one pop3_open() and pop3_header() go by.
     *
     * @return list<string>|null
     */
    private function capabilities(): ?array
    {
        if (!$this->capabilitiesRead) {
            $this->capabilities = $this->protocol->capa();
            $this->capabilitiesRead = true;
        }

        return $this->capabilities;
    }

    /**
     * Parsed once per message, as c-client keeps the BODY in the message
     * cache: what the parse logs is logged the first time only.
     */
    private function structure(int $uid): MimeBody
    {
        return $this->structureCache[$uid] ??= MimeBody::parse($this->rawMessage($uid));
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
