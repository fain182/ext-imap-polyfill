<?php

namespace ImapPolyfill\Session;

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
            ? \Webklex\PHPIMAP\IMAP::ST_UID
            : \Webklex\PHPIMAP\IMAP::ST_MSGN;

        $this->connection->ensureOpen();

        $tokens = preg_split('/\s+/', trim($criteria)) ?: [];

        try {
            $this->connection->selectOrExamine();
            $ids = $this->connection->protocol()->search($tokens, $uidMode);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        if ($ids === []) {
            return false;
        }

        return array_map('intval', $ids);
    }

    public function fetchHeader(int $messageNum, int $flags): string|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_fetchheader(): Argument #2 ($message_num) must be greater than 0');
        }

        $uidMode = ($flags & FT_UID)
            ? \Webklex\PHPIMAP\IMAP::ST_UID
            : \Webklex\PHPIMAP\IMAP::ST_MSGN;

        try {
            $this->connection->selectOrExamine();
            $headers = $this->connection->protocol()->headers([$messageNum], 'RFC822', $uidMode);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return $headers[$messageNum] ?? reset($headers);
    }

    public function headerInfo(int $messageNum): \stdClass|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_headerinfo(): Argument #2 ($message_num) must be greater than 0');
        }

        try {
            $this->connection->selectOrExamine();
            $data = $this->connection->protocol()->fetch(
                ['FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'],
                [$messageNum],
                null,
                \Webklex\PHPIMAP\IMAP::ST_MSGN,
            );
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $message = $data[$messageNum] ?? reset($data);

        return HeaderInfo::build(
            $message['RFC822.HEADER'],
            $message['FLAGS'],
            $message['INTERNALDATE'],
            $message['RFC822.SIZE'],
            $messageNum,
            $this->connection->host(),
        );
    }

    /**
     * @return \stdClass[]|false
     */
    public function fetchOverview(string $sequence, int $flags): array|false
    {
        $uidMode = ($flags & FT_UID)
            ? \Webklex\PHPIMAP\IMAP::ST_UID
            : \Webklex\PHPIMAP\IMAP::ST_MSGN;

        $this->connection->ensureOpen();

        try {
            $status = $this->connection->selectOrExamine();
            $ids = MessageSequence::parse($sequence)->expand($status['exists'] ?? 0);

            if ($ids === []) {
                return [];
            }

            $protocol = $this->connection->protocol();
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
            $uid = $uidMode === \Webklex\PHPIMAP\IMAP::ST_UID ? $id : (int) $message['UID'];
            $msgno = $uidMode === \Webklex\PHPIMAP\IMAP::ST_UID
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

        try {
            $this->connection->selectOrExamine();
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
            ? \Webklex\PHPIMAP\IMAP::ST_UID
            : \Webklex\PHPIMAP\IMAP::ST_MSGN;
        // ext-imap's section "0" is a legacy alias for the top-level header,
        // not a literal MIME part index.
        $wireSection = $section === '0' ? 'HEADER' : $section;
        $item = ($flags & FT_PEEK) ? "BODY.PEEK[{$wireSection}]" : "BODY[{$wireSection}]";

        try {
            $this->connection->selectOrExamine();
            $data = $this->connection->protocol()->fetch([$item], [$messageNum], null, $uidMode);
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
            ? \Webklex\PHPIMAP\IMAP::ST_UID
            : \Webklex\PHPIMAP\IMAP::ST_MSGN;
        $item = ($flags & FT_PEEK) ? "BODY.PEEK[{$section}.MIME]" : "BODY[{$section}.MIME]";

        try {
            $this->connection->selectOrExamine();
            $data = $this->connection->protocol()->fetch([$item], [$messageNum], null, $uidMode);
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
            ? \Webklex\PHPIMAP\IMAP::ST_UID
            : \Webklex\PHPIMAP\IMAP::ST_MSGN;
        $item = ($flags & FT_PEEK) ? 'BODY.PEEK[TEXT]' : 'BODY[TEXT]';

        try {
            $this->connection->selectOrExamine();
            $data = $this->connection->protocol()->fetch([$item], [$messageNum], null, $uidMode);
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
            ? \Webklex\PHPIMAP\IMAP::ST_UID
            : \Webklex\PHPIMAP\IMAP::ST_MSGN;

        try {
            $this->connection->selectOrExamine();
            // Unlike APPEND and STATUS, c-client's COPY sends the mailbox
            // argument verbatim on the wire — a "{host}folder" spec is not
            // unwrapped and simply names a nonexistent folder server-side.
            $this->connection->protocol()->copy($sequence, $folder, $uidMode);

            // c-client's CP_MOVE predates the IMAP MOVE extension: it marks
            // the source messages \Deleted after copying and leaves the
            // expunge to the caller.
            if ($options & CP_MOVE) {
                $command = ($options & CP_UID) ? 'UID STORE' : 'STORE';
                $this->connection->protocol()->store($command, [$sequence, '+FLAGS.SILENT', '(\\Deleted)']);
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

            $uids = $this->connection->protocol()->getUid();
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

        try {
            $this->connection->selectOrExamine();

            return (int) $this->connection->protocol()->getMessageNumber((string) $messageUid);
        } catch (\Webklex\PHPIMAP\Exceptions\MessageNotFoundException) {
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
            $this->connection->protocol()->store($command, [$sequence, '+FLAGS.SILENT', $flagsAtom]);
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
            $this->connection->protocol()->store($command, [$sequence, '-FLAGS.SILENT', $flagsAtom]);
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
            $data = $this->connection->protocol()->fetch(
                ['FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'],
                $ids,
                null,
                \Webklex\PHPIMAP\IMAP::ST_MSGN,
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

            if ($exists === 0) {
                return [];
            }

            if ($searchCriteria !== null) {
                $tokens = preg_split('/\s+/', trim($searchCriteria)) ?: [];
                $ids = $this->connection->protocol()->search($tokens, \Webklex\PHPIMAP\IMAP::ST_MSGN);

                if ($ids === []) {
                    return [];
                }
            } else {
                $ids = range(1, $exists);
            }

            $data = $this->connection->protocol()->fetch(
                ['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'],
                $ids,
                null,
                \Webklex\PHPIMAP\IMAP::ST_MSGN,
            );
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
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

            $ids = range(1, $exists);
            $data = $this->connection->protocol()->fetch(
                ['UID', 'INTERNALDATE', 'RFC822.HEADER'],
                $ids,
                null,
                \Webklex\PHPIMAP\IMAP::ST_MSGN,
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
