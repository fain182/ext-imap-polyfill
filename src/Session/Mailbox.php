<?php

namespace ImapPolyfill\Session;

use ImapPolyfill\Connection\MessageNotFoundException;
use ImapPolyfill\Connection\UidMode;
use ImapPolyfill\Mailbox\MailboxReference;
use ImapPolyfill\Message\BodyStructure;
use ImapPolyfill\Message\HeaderInfo;
use ImapPolyfill\Message\HeadersLine;
use ImapPolyfill\Message\MessageSequence;
use ImapPolyfill\Message\Overview;
use ImapPolyfill\Message\SortKey;
use ImapPolyfill\Message\ThreadBuilder;
use ImapPolyfill\Support\ErrorStack;

/**
 * Operations on the mailbox currently selected on an open \IMAP\Connection:
 * searching, fetching, flagging, and appending messages within it.
 */
final class Mailbox
{
    public function __construct(private readonly \IMAP\Connection $connection)
    {
    }

    /**
     * @return int[]|false
     */
    public function search(string $criteria, int $flags, string $charset): array|false
    {
        $uidMode = ($flags & SE_UID)
            ? UidMode::UID
            : UidMode::MSGNO;

        $this->connection->ensureOpen();

        $tokens = preg_split('/\s+/', trim($criteria)) ?: [];

        // Nothing to look through: c-client answers from the count it
        // holds rather than asking, so no SEARCH goes out and a folder
        // that has gone away is not an error, just empty.
        if ($this->selectionCovering(1, UidMode::MSGNO) === false) {
            return false;
        }

        try {
            $ids = $this->connection->backend()->search($tokens, $uidMode, $charset);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        if ($ids === []) {
            return false;
        }

        return array_map('intval', $ids);
    }

    /**
     * Selects the folder and answers its status, or false when
     * $messageNum is past the end of it.
     *
     * c-client checks the number against the count it already holds and
     * answers from that: past the end is simply absent, so no FETCH goes
     * out and nothing is logged. A UID is not a position in the folder, so
     * it is not checked this way. A selection that fails outright is
     * another matter, and is reported as any failure is.
     *
     * @return array<string, mixed>|false
     */
    private function selectionCovering(int $messageNum, int $uidMode): array|false
    {
        try {
            $status = $this->connection->selectOrExamine();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        if ($uidMode !== UidMode::UID && $messageNum > (int) ($status['exists'] ?? 0)) {
            return false;
        }

        return $status;
    }

    public function fetchHeader(int $messageNum, int $flags): string|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_fetchheader(): Argument #2 ($message_num) must be greater than 0');
        }

        $uidMode = ($flags & FT_UID)
            ? UidMode::UID
            : UidMode::MSGNO;

        if ($this->selectionCovering($messageNum, $uidMode) === false) {
            return false;
        }

        try {
            $headers = $this->connection->backend()->headers([$messageNum], 'RFC822', $uidMode);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return $headers[$messageNum] ?? reset($headers);
    }

    public function headerInfo(int $messageNum, int $fromLength = 0, int $subjectLength = 0): \stdClass|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_headerinfo(): Argument #2 ($message_num) must be greater than 0');
        }

        // 1024 is c-client's MAILTMPLEN, the buffer php_imap.c formats into.
        if ($fromLength < 0 || $fromLength > 1024) {
            throw new \ValueError('imap_headerinfo(): Argument #3 ($from_length) must be between 0 and 1024');
        }

        if ($subjectLength < 0 || $subjectLength > 1024) {
            throw new \ValueError('imap_headerinfo(): Argument #4 ($subject_length) must be between 0 and 1024');
        }

        try {
            $status = $this->connection->selectOrExamine();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        // c-client checks the number against the count it already holds and
        // answers from that, so a message past the end of the folder is
        // simply absent rather than a failed FETCH — nothing to report, and
        // nothing said to the server.
        if ($messageNum > (int) ($status['exists'] ?? 0)) {
            return false;
        }

        try {
            $data = $this->connection->backend()->fetch(
                HeaderInfo::FETCH_ITEMS,
                [$messageNum],
                null,
                UidMode::MSGNO,
            );
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $message = $data[$messageNum] ?? reset($data);

        if (!is_array($message)) {
            return false;
        }

        return HeaderInfo::build(
            $message['RFC822.HEADER'],
            $message['FLAGS'],
            $message['INTERNALDATE'],
            $message['RFC822.SIZE'],
            $messageNum,
            $this->connection->host(),
            $fromLength,
            $subjectLength,
        );
    }

    /**
     * @return \stdClass[]|false
     */
    public function fetchOverview(string $sequence, int $flags): array|false
    {
        $uidMode = ($flags & FT_UID)
            ? UidMode::UID
            : UidMode::MSGNO;

        $this->connection->ensureOpen();

        try {
            $status = $this->connection->selectOrExamine();
            $exists = (int) ($status['exists'] ?? 0);
            $ids = MessageSequence::parse($sequence)->expand($exists);

            if ($ids === []) {
                return [];
            }

            // c-client's mail_sequence() checks the numbers against the
            // count it holds and refuses the lot if any is past the end,
            // writing this itself — the one message on this path that is
            // not the server's own words. A UID is not a position, so a
            // UID sequence is not checked this way.
            if ($uidMode !== UidMode::UID && max($ids) > $exists) {
                ErrorStack::push('Sequence out of range');

                return [];
            }

            $protocol = $this->connection->backend();
            $data = $protocol->fetch(['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'], $ids, null, $uidMode);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            // Observed real ext-imap behavior: a broken connection yields an
            // empty result set here, not false (unlike most other fetch
            // functions in this file).
            return [];
        }

        $result = [];
        foreach ($ids as $id) {
            if (!isset($data[$id])) {
                continue;
            }

            $message = $data[$id];
            $uid = $uidMode === UidMode::UID ? $id : (int) $message['UID'];
            $msgno = $uidMode === UidMode::UID
                ? $protocol->getMessageNumber((string) $id)
                : $id;

            $result[] = Overview::build(
                $message['RFC822.HEADER'],
                $message['FLAGS'],
                $message['INTERNALDATE'],
                (int) $message['RFC822.SIZE'],
                $uid,
                $msgno,
                $this->connection->host(),
            );
        }

        return $result;
    }

    public function fetchStructure(int $messageNum, int $flags): \stdClass|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_fetchstructure(): Argument #2 ($message_num) must be greater than 0');
        }

        $uidMode = ($flags & FT_UID) ? UidMode::UID : UidMode::MSGNO;

        if ($this->selectionCovering($messageNum, $uidMode) === false) {
            return false;
        }

        try {
            $parsed = $this->connection->fetchBodyStructure($messageNum, (bool) ($flags & FT_UID));
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return BodyStructure::build($parsed);
    }

    public function fetchBody(int $messageNum, string $section, int $flags): string|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_fetchbody(): Argument #2 ($message_num) must be greater than 0');
        }

        $uidMode = ($flags & FT_UID)
            ? UidMode::UID
            : UidMode::MSGNO;
        // ext-imap's section "0" is a legacy alias for the top-level header,
        // not a literal MIME part index.
        $wireSection = $section === '0' ? 'HEADER' : $section;
        $item = ($flags & FT_PEEK) ? "BODY.PEEK[{$wireSection}]" : "BODY[{$wireSection}]";

        if ($this->selectionCovering($messageNum, $uidMode) === false) {
            return false;
        }

        // A numbered section may not be there at all, and c-client answers
        // that from the structure rather than from the server: asking for
        // part 2 of a message that has one part is an empty string, not
        // the body over again. It has to be settled before the body is
        // asked for, not after — a server that cannot read the message at
        // all still owes the same empty string, and one of the fixtures
        // refuses the FETCH outright when the charset means nothing to it.
        //
        // Section 1 is the exception worth making: every structure has
        // one, so the answer is known without asking, and it is the
        // section every caller reaches for first.
        if ($wireSection !== '1' && preg_match('/^\d+(?:\.\d+)*$/', $wireSection) === 1) {
            try {
                $structure = $this->connection->fetchBodyStructure($messageNum, $uidMode === UidMode::UID);
            } catch (\Throwable $e) {
                ErrorStack::push($e->getMessage());

                return false;
            }

            if (BodyStructure::resolveSection($structure, $wireSection) === null) {
                return '';
            }
        }

        try {
            $data = $this->connection->backend()->fetch([$item], [$messageNum], null, $uidMode);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return $data[$messageNum] ?? reset($data);
    }

    public function fetchMime(int $messageNum, string $section, int $flags): string|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_fetchmime(): Argument #2 ($message_num) must be greater than 0');
        }

        if ($flags !== 0 && ($flags & ~(FT_UID | FT_PEEK | FT_INTERNAL)) !== 0) {
            throw new \ValueError('imap_fetchmime(): Argument #4 ($flags) must be a bitmask of FT_UID, FT_PEEK, and FT_INTERNAL');
        }

        $uidMode = ($flags & FT_UID)
            ? UidMode::UID
            : UidMode::MSGNO;
        $item = ($flags & FT_PEEK) ? "BODY.PEEK[{$section}.MIME]" : "BODY[{$section}.MIME]";

        if ($this->selectionCovering($messageNum, $uidMode) === false) {
            return false;
        }

        try {
            $data = $this->connection->backend()->fetch([$item], [$messageNum], null, $uidMode);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return $data[$messageNum] ?? reset($data);
    }

    public function bodyStruct(int $messageNum, string $section): \stdClass|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_bodystruct(): Argument #2 ($message_num) must be greater than 0');
        }

        try {
            $this->connection->selectOrExamine();
            // c-client's mail_body() indexes a single BODYSTRUCTURE fetch by
            // section, unlike imap_fetchbody(): there is no msgno/uid
            // equivalent of BODYSTRUCTURE for one section, so this is always
            // a msgno, never a uid (no FT_UID here, unlike imap_fetchbody()).
            $parsed = $this->connection->fetchBodyStructure($messageNum, false);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $node = BodyStructure::resolveSection($parsed, $section);

        if ($node === null) {
            return false;
        }

        return BodyStructure::build($node);
    }

    /**
     * @param resource|string $file
     */
    public function saveBody(mixed $file, int $messageNum, string $section, int $flags): bool
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_savebody(): Argument #3 ($message_num) must be greater than 0');
        }

        if ($flags !== 0 && ($flags & ~(FT_UID | FT_PEEK | FT_INTERNAL)) !== 0) {
            throw new \ValueError('imap_savebody(): Argument #5 ($flags) must be a bitmask of FT_UID, FT_PEEK, and FT_INTERNAL');
        }

        $isResource = is_resource($file);
        if ($isResource) {
            $handle = $file;
        } else {
            $handle = @fopen((string) $file, 'wb');
            if ($handle === false) {
                return false;
            }
        }

        // ext-imap's C implementation never checks whether the underlying
        // mail_fetchbody_full() call actually produced anything — it just
        // writes whatever it got (nothing, for an invalid section) and
        // returns true as long as the destination could be opened.
        $body = $this->fetchBody($messageNum, $section, $flags);
        fwrite($handle, $body === false ? '' : $body);

        if (!$isResource) {
            fclose($handle);
        }

        return true;
    }

    public function body(int $messageNum, int $flags): string|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_body(): Argument #2 ($message_num) must be greater than 0');
        }

        if (($flags & ~(FT_UID | FT_PEEK | FT_INTERNAL)) !== 0) {
            throw new \ValueError('imap_body(): Argument #3 ($flags) must be a bitmask of FT_UID, FT_PEEK, and FT_INTERNAL');
        }

        $uidMode = ($flags & FT_UID)
            ? UidMode::UID
            : UidMode::MSGNO;
        $item = ($flags & FT_PEEK) ? 'BODY.PEEK[TEXT]' : 'BODY[TEXT]';

        if ($this->selectionCovering($messageNum, $uidMode) === false) {
            return false;
        }

        try {
            $data = $this->connection->backend()->fetch([$item], [$messageNum], null, $uidMode);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return $data[$messageNum] ?? reset($data);
    }

    public function copy(string $sequence, string $folder, int $options): bool
    {
        $this->connection->ensureOpen();

        if (($options & ~(CP_UID | CP_MOVE)) !== 0) {
            throw new \ValueError('imap_mail_copy(): Argument #4 ($options) must be a bitmask of CP_UID, and CP_MOVE');
        }

        return $this->copyTo($sequence, $folder, $options);
    }

    public function move(string $sequence, string $folder, int $options): bool
    {
        $this->connection->ensureOpen();

        if (($options & ~CP_UID) !== 0) {
            throw new \ValueError('imap_mail_move(): Argument #4 ($options) must be CP_UID or 0');
        }

        return $this->copyTo($sequence, $folder, $options | CP_MOVE);
    }

    private function copyTo(string $sequence, string $folder, int $options): bool
    {
        $uidMode = ($options & CP_UID)
            ? UidMode::UID
            : UidMode::MSGNO;

        try {
            $this->connection->selectOrExamine();
            // Unlike APPEND and STATUS, c-client's COPY sends the mailbox
            // argument verbatim on the wire — a "{host}folder" spec is not
            // unwrapped and simply names a nonexistent folder server-side.
            $this->connection->backend()->copy($sequence, $folder, $uidMode);

            // c-client's CP_MOVE predates the IMAP MOVE extension: it marks
            // the source messages \Deleted after copying and leaves the
            // expunge to the caller.
            if ($options & CP_MOVE) {
                $command = ($options & CP_UID) ? 'UID STORE' : 'STORE';
                $this->connection->backend()->store($command, [$sequence, '+FLAGS.SILENT', '(\\Deleted)']);
            }
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }

    public function uid(int $messageNum): int|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_uid(): Argument #2 ($message_num) must be greater than 0');
        }

        try {
            $status = $this->connection->selectOrExamine();

            if ($messageNum > ($status['exists'] ?? 0)) {
                trigger_error('imap_uid(): Bad message number', E_USER_WARNING);

                return false;
            }

            $uids = $this->connection->backend()->getUid();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return (int) $uids[$messageNum];
    }

    public function msgno(int $messageUid): int
    {
        $this->connection->ensureOpen();

        if ($messageUid < 1) {
            throw new \ValueError('imap_msgno(): Argument #2 ($message_uid) must be greater than 0');
        }

        if ($this->selectionCovering(1, UidMode::MSGNO) === false) {
            return 0;
        }

        try {
            return (int) $this->connection->backend()->getMessageNumber((string) $messageUid);
        } catch (MessageNotFoundException) {
            return 0;
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return 0;
        }
    }

    public function setFlagFull(string $sequence, string $flag, int $options): bool
    {
        $this->connection->ensureOpen();

        if (($options & ~ST_UID) !== 0) {
            throw new \ValueError('imap_setflag_full(): Argument #4 ($options) must be ST_UID or 0');
        }

        $command = ($options & ST_UID) ? 'UID STORE' : 'STORE';
        $flagsAtom = '('.trim($flag).')';

        try {
            $this->connection->selectOrExamine();
            $this->connection->backend()->store($command, [$sequence, '+FLAGS.SILENT', $flagsAtom]);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());
        }

        return true;
    }

    public function clearFlagFull(string $sequence, string $flag, int $options): bool
    {
        $this->connection->ensureOpen();

        if (($options & ~ST_UID) !== 0) {
            throw new \ValueError('imap_clearflag_full(): Argument #4 ($options) must be ST_UID or 0');
        }

        $command = ($options & ST_UID) ? 'UID STORE' : 'STORE';
        $flagsAtom = '('.trim($flag).')';

        try {
            $this->connection->selectOrExamine();
            $this->connection->backend()->store($command, [$sequence, '-FLAGS.SILENT', $flagsAtom]);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());
        }

        return true;
    }

    public function expunge(): bool
    {
        $this->connection->ensureOpen();

        try {
            $this->connection->selectOrExamine();
            $this->connection->expunge();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());
        }

        return true;
    }

    public function append(string $folder, string $message, ?string $options, ?string $internalDate): bool
    {
        $this->connection->ensureOpen();

        $folderName = MailboxReference::parse($folder)->bareReference;
        $flags = $options !== null ? (preg_split('/\s+/', trim($options)) ?: []) : null;

        try {
            $this->connection->appendMessage($folderName, $message, $flags, $internalDate);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @return string[]
     */
    public function headers(): array
    {
        $this->connection->ensureOpen();

        try {
            $status = $this->connection->selectOrExamine();
            $exists = $status['exists'] ?? 0;

            if ($exists === 0) {
                return [];
            }

            $ids = range(1, $exists);
            $data = $this->connection->backend()->fetch(
                HeaderInfo::FETCH_ITEMS,
                $ids,
                null,
                UidMode::MSGNO,
            );
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return [];
        }

        $result = [];
        foreach ($ids as $msgno) {
            if (!isset($data[$msgno])) {
                continue;
            }

            $message = $data[$msgno];
            $result[] = HeadersLine::build(
                $message['RFC822.HEADER'],
                $message['FLAGS'],
                $message['INTERNALDATE'],
                (int) $message['RFC822.SIZE'],
                $msgno,
                $this->connection->host(),
                $this->connection->userFlags(),
            );
        }

        return $result;
    }

    /**
     * @return int[]|false
     */
    public function sort(int $criteria, bool $reverse, int $flags, ?string $searchCriteria, ?string $charset): array|false
    {
        $this->connection->ensureOpen();

        if (!in_array($criteria, [SORTDATE, SORTARRIVAL, SORTFROM, SORTSUBJECT, SORTTO, SORTCC, SORTSIZE], true)) {
            throw new \ValueError('imap_sort(): Argument #2 ($criteria) must be one of the SORT* constants');
        }

        if ($flags && ($flags & ~(SE_UID | SE_NOPREFETCH)) !== 0) {
            throw new \ValueError('imap_sort(): Argument #4 ($flags) must be a bitmask of SE_UID, and SE_NOPREFETCH');
        }

        try {
            $status = $this->connection->selectOrExamine();
            $exists = $status['exists'] ?? 0;

            // c-client hands the whole sort to the server whenever it
            // advertises SORT, and only falls back to its own algorithms when
            // the server has none or rejects the command (imap4r1.c
            // imap_sort). An absent search program is the empty SEARCHPGM,
            // which serializes to ALL.
            if ($this->connection->backend()->hasCapability('SORT')) {
                $sorted = $this->connection->backend()->sort(
                    ($reverse ? 'REVERSE ' : '').SortKey::wireName($criteria),
                    $charset ?? 'US-ASCII',
                    $searchCriteria !== null ? (preg_split('/\s+/', trim($searchCriteria)) ?: []) : ['ALL'],
                    ($flags & SE_UID) ? UidMode::UID : UidMode::MSGNO,
                );

                if ($sorted !== null) {
                    return $sorted;
                }
            }

            // Only the local algorithms need the count: an empty folder
            // has no range to walk. The server was still asked first, so a
            // connection that has gone is reported rather than passed over.
            if ($exists === 0) {
                return [];
            }

            if ($searchCriteria !== null) {
                $tokens = preg_split('/\s+/', trim($searchCriteria)) ?: [];
                $ids = $this->connection->backend()->search($tokens, UidMode::MSGNO);

                if ($ids === []) {
                    return [];
                }
            } else {
                $ids = range(1, $exists);
            }

            $data = $this->connection->backend()->fetch(
                ['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'],
                $ids,
                null,
                UidMode::MSGNO,
            );
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            // php_imap.c fills an array from whatever mail_sort() gave it,
            // so a failed sort is an empty result rather than false.
            return [];
        }

        $host = $this->connection->host();
        $entries = [];
        foreach ($ids as $msgno) {
            if (!isset($data[$msgno])) {
                continue;
            }

            $message = $data[$msgno];
            $entries[] = [
                'msgno' => $msgno,
                'uid' => (int) $message['UID'],
                'key' => SortKey::resolve($criteria, $message, $host),
            ];
        }

        usort($entries, static function (array $a, array $b) use ($reverse): int {
            $cmp = $a['key'] <=> $b['key'];
            if ($cmp === 0) {
                $cmp = $a['msgno'] <=> $b['msgno'];
            }

            return $reverse ? -$cmp : $cmp;
        });

        $byUid = (bool) ($flags & SE_UID);

        return array_map(static fn (array $e): int => $byUid ? $e['uid'] : $e['msgno'], $entries);
    }

    /**
     * @return array<string, int>|false
     */
    public function thread(int $flags): array|false
    {
        $this->connection->ensureOpen();

        try {
            $status = $this->connection->selectOrExamine();
            $exists = $status['exists'] ?? 0;

            if ($exists === 0) {
                return false;
            }

            // c-client threads on the server whenever it advertises the
            // algorithm asked for, and only falls back to its own REFERENCES
            // implementation otherwise (imap4r1.c imap_thread). php_imap.c
            // always asks for REFERENCES over the whole mailbox ("ALL").
            if ($this->connection->backend()->hasCapability('THREAD=REFERENCES')) {
                $byUid = (bool) ($flags & SE_UID);
                $groups = $this->connection->backend()->thread(
                    'REFERENCES',
                    'US-ASCII',
                    ['ALL'],
                    $byUid ? UidMode::UID : UidMode::MSGNO,
                );

                if ($groups !== null) {
                    $tree = ThreadBuilder::flatten(ThreadBuilder::containersFromServer($groups, $byUid), $byUid);

                    return $tree === [] ? false : $tree;
                }
            }

            $ids = range(1, $exists);
            $data = $this->connection->backend()->fetch(
                ['UID', 'INTERNALDATE', 'RFC822.HEADER'],
                $ids,
                null,
                UidMode::MSGNO,
            );
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $messages = ThreadBuilder::messagesFromFetch($data, $ids);
        $root = ThreadBuilder::build($messages);
        $tree = ThreadBuilder::flatten($root, (bool) ($flags & SE_UID));

        if ($tree === []) {
            return false;
        }

        return $tree;
    }
}
