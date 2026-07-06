<?php

namespace ImapPolyfill\Session;

use ImapPolyfill\Mailbox\MailboxReference;
use ImapPolyfill\Mailbox\MailboxSpec;
use ImapPolyfill\Message\BodyStructure;
use ImapPolyfill\Message\BodyStructureFetch;
use ImapPolyfill\Message\HeaderInfo;
use ImapPolyfill\Message\MessageSequence;
use ImapPolyfill\Message\Overview;
use ImapPolyfill\Support\ErrorStack;

/**
 * Orchestrates a single imap_*() call against an already-open \IMAP\Connection,
 * delegating to the collaborator that knows how to parse/build each shape.
 */
final class Session
{
    public function __construct(private readonly \IMAP\Connection $connection)
    {
    }

    public function close(int $flags): bool
    {
        $this->connection->ensureOpen();

        if ($flags & CL_EXPUNGE) {
            $this->connection->client->expunge();
        }

        $this->connection->client->disconnect();
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
        $result->Driver = 'imap';
        $result->Mailbox = $this->connection->mailbox;
        $result->Nmsgs = $status['exists'] ?? 0;
        $result->Recent = $status['recent'] ?? 0;

        return $result;
    }

    public function isOpen(): bool
    {
        return $this->connection->isOpen();
    }

    /**
     * Scoped to switching folders on the same already-connected client: this
     * polyfill doesn't retain the original credentials needed to reconnect
     * to a genuinely different host.
     */
    public function reopen(string $mailbox, int $flags): bool
    {
        $this->connection->ensureOpen();

        $spec = MailboxSpec::parse($mailbox);
        $readOnly = (bool) ($flags & OP_READONLY);

        try {
            $folder = $this->connection->client->getFolder($spec->folder);
            $status = $readOnly ? $folder->examine() : $folder->select();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $this->connection->reselect($spec->folder, $readOnly);
        $this->connection->rememberCounts($status['exists'] ?? 0, $status['recent'] ?? 0);

        return true;
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
            $ids = $this->connection->client->getConnection()->search($tokens, $uidMode)->validatedData();
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
            $headers = $this->connection->client->getConnection()->headers([$messageNum], 'RFC822', $uidMode)->validatedData();
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
            $data = $this->connection->client->getConnection()
                ->fetch(['FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'], [$messageNum], null, \Webklex\PHPIMAP\IMAP::ST_MSGN)
                ->validatedData();
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
            $this->connection->client->host,
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

            $connection = $this->connection->client->getConnection();
            $data = $connection
                ->fetch(['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'], $ids, null, $uidMode)
                ->validatedData();
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
                ? $connection->getMessageNumber((string) $id)->validatedData()
                : $id;

            $result[] = Overview::build(
                $message['RFC822.HEADER'],
                $message['FLAGS'],
                $message['INTERNALDATE'],
                (int) $message['RFC822.SIZE'],
                $uid,
                $msgno,
                $this->connection->client->host,
            );
        }

        return $result;
    }

    public function fetchStructure(int $messageNum, int $flags): \stdClass|false
    {
        $this->connection->ensureOpen();

        try {
            $this->connection->selectOrExamine();
            $parsed = BodyStructureFetch::fetch($this->connection->client, $messageNum, (bool) ($flags & FT_UID));
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
            $data = $this->connection->client->getConnection()->fetch([$item], [$messageNum], null, $uidMode)->validatedData();
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

            $uids = $this->connection->client->getConnection()->getUid()->validatedData();
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

            return (int) $this->connection->client->getConnection()->getMessageNumber((string) $messageUid)->validatedData();
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
            $this->connection->client->getConnection()->requestAndResponse($command, [$sequence, '+FLAGS.SILENT', $flagsAtom]);
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
            $this->connection->client->getConnection()->requestAndResponse($command, [$sequence, '-FLAGS.SILENT', $flagsAtom]);
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
            $this->connection->client->expunge();
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
            $this->connection->client->getFolder($folderName)->appendMessage($message, $flags, $internalDate);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @return string[]|false
     */
    public function listMailboxes(string $reference, string $pattern): array|false
    {
        $this->connection->ensureOpen();

        $ref = MailboxReference::parse($reference);

        try {
            $folders = $this->connection->client->getConnection()->folders($ref->bareReference, $pattern)->validatedData();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        if ($folders === []) {
            return false;
        }

        return array_map(static fn (string $name): string => $ref->displayPrefix.$name, array_keys($folders));
    }

    /**
     * @return \stdClass[]|false
     */
    public function getMailboxes(string $reference, string $pattern): array|false
    {
        $this->connection->ensureOpen();

        $ref = MailboxReference::parse($reference);

        $flagBits = [
            '\noinferiors' => LATT_NOINFERIORS,
            '\noselect' => LATT_NOSELECT,
            '\marked' => LATT_MARKED,
            '\unmarked' => LATT_UNMARKED,
            '\haschildren' => LATT_HASCHILDREN,
            '\hasnochildren' => LATT_HASNOCHILDREN,
        ];

        try {
            $folders = $this->connection->client->getConnection()->folders($ref->bareReference, $pattern)->validatedData();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        if ($folders === []) {
            return false;
        }

        $result = [];
        foreach ($folders as $name => $info) {
            $attributes = 0;
            foreach ($info['flags'] as $flag) {
                $attributes |= $flagBits[strtolower($flag)] ?? 0;
            }

            $mailbox = new \stdClass();
            $mailbox->name = $ref->displayPrefix.$name;
            $mailbox->attributes = $attributes;
            $mailbox->delimiter = $info['delimiter'];
            $result[] = $mailbox;
        }

        return $result;
    }

    public function createMailbox(string $mailbox): bool
    {
        $this->connection->ensureOpen();

        $folderName = MailboxReference::parse($mailbox)->bareReference;

        try {
            $this->connection->client->createFolder($folderName);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }

    public function deleteMailbox(string $mailbox): bool
    {
        $this->connection->ensureOpen();

        $folderName = MailboxReference::parse($mailbox)->bareReference;

        try {
            $this->connection->client->deleteFolder($folderName);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }

    public function renameMailbox(string $from, string $to): bool
    {
        $this->connection->ensureOpen();

        $fromName = MailboxReference::parse($from)->bareReference;
        $toName = MailboxReference::parse($to)->bareReference;

        try {
            $this->connection->client->getFolder($fromName)->rename($toName);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }

    public function subscribe(string $mailbox): bool
    {
        $this->connection->ensureOpen();

        $folderName = MailboxReference::parse($mailbox)->bareReference;

        try {
            $this->connection->client->getFolder($folderName)->subscribe();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }

    public function unsubscribe(string $mailbox): bool
    {
        $this->connection->ensureOpen();

        $folderName = MailboxReference::parse($mailbox)->bareReference;

        try {
            $this->connection->client->getFolder($folderName)->unsubscribe();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }
}
