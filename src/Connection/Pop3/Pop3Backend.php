<?php

namespace ImapPolyfill\Connection\Pop3;

use ImapPolyfill\Connection\ConnectionBackend;
use ImapPolyfill\Message\MessageSequence;
use Webklex\PHPIMAP\Exceptions\MessageNotFoundException;

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
    private const UID_MODE = \Webklex\PHPIMAP\IMAP::ST_UID;

    /** Fake INTERNALDATE: POP3 has none, and real ext-imap reports the epoch. */
    private const FAKE_INTERNAL_DATE = ' 1-Jan-1970 00:00:00 +0000';

    /** @var array<int, string> msgno => uid, refreshed on every select */
    private array $uidByMsgno = [];

    /** @var array<string, int> uid => msgno */
    private array $msgnoByUid = [];

    /** @var array<int, string[]> msgno => IMAP flag names set on it, session-scoped */
    private array $flags = [];

    /** @var array<int, string> raw RETR cache for the lifetime of the connection */
    private array $rawMessageCache = [];

    private int $exists = 0;

    public function __construct(
        private readonly Pop3Protocol $protocol,
        private readonly string $host,
        private readonly string $mailboxSpec,
    ) {
    }

    public function driverName(): string
    {
        return 'pop3';
    }

    public function selectOrExamineFolder(string $folder, bool $readOnly): array
    {
        if ($readOnly) {
            throw new \RuntimeException('Read-only POP3 access not available');
        }

        if (strcasecmp($folder, 'INBOX') !== 0) {
            throw new \RuntimeException("Can't open mailbox {$this->mailboxSpec}: invalid remote specification");
        }

        $this->exists = $this->protocol->stat();
        $this->msgnoByUid = [];
        foreach ($this->protocol->uidl() as $msgno => $uid) {
            $this->uidByMsgno[$msgno] = $uid;
            $this->msgnoByUid[$uid] = $msgno;
        }

        // POP3 has no concept of a message having been seen in a previous
        // session; every message the server still has is "recent" (matches
        // real ext-imap's observed imap_status()/imap_check() output).
        return ['exists' => $this->exists, 'recent' => $this->exists];
    }

    public function host(): string
    {
        return $this->host;
    }

    public function expunge(): void
    {
        // No-op: DELE already removes the message from STAT/LIST/UIDL as
        // soon as store() issues it (RFC1939), there is no separate wire
        // command left to send.
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
        $msgno = $byUid ? $this->resolveMsgno($messageNum) : $messageNum;

        return Pop3MimeStructure::parse($this->rawMessage($msgno));
    }

    public function search(array $tokens, int $uidMode): array
    {
        $ids = [];
        foreach ($this->uidByMsgno as $msgno => $uid) {
            if (Pop3SearchEvaluator::matches($tokens, $this->rawMessage($msgno), $this->flags[$msgno] ?? [])) {
                $ids[] = $uidMode === self::UID_MODE ? (int) $uid : $msgno;
            }
        }

        return $ids;
    }

    public function headers(array $ids, string $type, int $uidMode): array
    {
        $result = [];
        foreach ($ids as $id) {
            $msgno = $uidMode === self::UID_MODE ? $this->resolveMsgno($id) : $id;
            [$header] = $this->splitHeaderBody($this->rawMessage($msgno));
            $result[$id] = $header."\r\n";
        }

        return $result;
    }

    public function fetch(array $items, array $ids, ?int $to, int $uidMode): array
    {
        $result = [];
        foreach ($ids as $id) {
            $msgno = $uidMode === self::UID_MODE ? $this->resolveMsgno($id) : $id;
            $raw = $this->rawMessage($msgno);
            [$header, $body] = $this->splitHeaderBody($raw);

            $entry = [];
            foreach ($items as $item) {
                $entry[$item] = $this->fetchItem($item, $msgno, $raw, $header, $body);
            }

            // Mirrors webklex's own fetch() response shape: a single
            // requested item collapses to its scalar value per id instead
            // of a one-key array (see Mailbox::body()/fetchBody()/fetchMime()
            // vs. headerInfo()/fetchOverview(), which read it back that way).
            $result[$id] = count($items) === 1 ? reset($entry) : $entry;
        }

        return $result;
    }

    private function fetchItem(string $item, int $msgno, string $raw, string $header, string $body): mixed
    {
        return match (true) {
            $item === 'FLAGS' => $this->flags[$msgno] ?? [],
            $item === 'INTERNALDATE' => self::FAKE_INTERNAL_DATE,
            $item === 'RFC822.SIZE' => (string) strlen($raw),
            $item === 'RFC822.HEADER' => $header."\r\n",
            $item === 'UID' => $this->uidByMsgno[$msgno] ?? '',
            str_starts_with($item, 'BODY.PEEK[') || str_starts_with($item, 'BODY[') => $this->fetchSection($item, $header, $body),
            default => '',
        };
    }

    private function fetchSection(string $item, string $header, string $body): string
    {
        $section = substr($item, strpos($item, '[') + 1, -1);

        return match (true) {
            $section === 'TEXT' => $body,
            $section === 'HEADER' => $header."\r\n",
            str_ends_with($section, '.MIME') => $header."\r\n",
            default => $body,
        };
    }

    public function getUid(): array
    {
        return array_map('intval', $this->uidByMsgno);
    }

    public function getMessageNumber(string $uid): int
    {
        return $this->resolveMsgno($uid);
    }

    public function store(string $command, array $args): void
    {
        [$sequence, $action, $flagsAtom] = $args;
        $flagNames = array_filter(explode(' ', trim($flagsAtom, '()')));
        $adding = str_starts_with($action, '+');
        $byUid = str_starts_with($command, 'UID');

        foreach (MessageSequence::parse($sequence)->expand($this->exists) as $id) {
            $msgno = $byUid ? $this->resolveMsgno((string) $id) : $id;
            $current = $this->flags[$msgno] ?? [];

            foreach ($flagNames as $flag) {
                $current = $adding
                    ? array_unique([...$current, $flag])
                    : array_values(array_diff($current, [$flag]));
            }

            $this->flags[$msgno] = $current;

            if ($adding && in_array('\\Deleted', $flagNames, true)) {
                $this->protocol->dele($msgno);
                $this->exists--;
            }
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

    public function copy(string $sequence, string $folder, int $uidMode): void
    {
        throw new \RuntimeException('Copy not valid for POP3');
    }

    public function noop(): void
    {
        $this->protocol->noop();
    }

    public function folderStatus(string $folder, array $items): array
    {
        $unseen = 0;
        foreach ($this->uidByMsgno as $msgno => $uid) {
            if (!in_array('\\Seen', $this->flags[$msgno] ?? [], true)) {
                $unseen++;
            }
        }

        return [
            'messages' => $this->exists,
            'recent' => $this->exists,
            'unseen' => $unseen,
            'uidnext' => $this->exists + 1,
            'uidvalidity' => 1,
        ];
    }

    private function rawMessage(int $msgno): string
    {
        return $this->rawMessageCache[$msgno] ??= $this->protocol->retr($msgno);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitHeaderBody(string $raw): array
    {
        $pos = preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;

        if ($pos === null) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $pos), substr($raw, $pos + strlen($m[0][0]))];
    }

    private function resolveMsgno(int|string $uid): int
    {
        if (!isset($this->msgnoByUid[(string) $uid])) {
            throw new MessageNotFoundException("Message with uid {$uid} not found");
        }

        return $this->msgnoByUid[(string) $uid];
    }
}
