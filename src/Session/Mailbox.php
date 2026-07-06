<?php

namespace ImapPolyfill\Session;

use ImapPolyfill\Mailbox\MailboxReference;
use ImapPolyfill\Message\BodyStructure;
use ImapPolyfill\Message\HeaderInfo;
use ImapPolyfill\Message\MessageSequence;
use ImapPolyfill\Message\Overview;
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

        $tokens = preg_split('/\s+/', trim($criteria));

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
        $uidMode = ($flags & FT_UID)
            ? \Webklex\PHPIMAP\IMAP::ST_UID
            : \Webklex\PHPIMAP\IMAP::ST_MSGN;

        $this->connection->ensureOpen();

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
        $flags = $options !== null ? preg_split('/\s+/', trim($options)) : null;

        try {
            $this->connection->appendMessage($folderName, $message, $flags, $internalDate);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }
}
