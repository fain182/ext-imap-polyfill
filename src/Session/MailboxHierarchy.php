<?php

namespace ImapPolyfill\Session;

use ImapPolyfill\Mailbox\MailboxReference;
use ImapPolyfill\Support\ErrorStack;

/**
 * The mailbox hierarchy reachable from an open \IMAP\Connection: listing,
 * creating, deleting, renaming, and (un)subscribing to folders, without any
 * of them being selected.
 */
final class MailboxHierarchy
{
    public function __construct(private readonly \IMAP\Connection $connection)
    {
    }

    /**
     * @return string[]|false
     */
    public function listMailboxes(string $reference, string $pattern): array|false
    {
        return $this->names($reference, $pattern, subscribedOnly: false);
    }

    /**
     * @return string[]|false
     */
    public function listSubscribed(string $reference, string $pattern): array|false
    {
        return $this->names($reference, $pattern, subscribedOnly: true);
    }

    /**
     * @return \stdClass[]|false
     */
    public function getMailboxes(string $reference, string $pattern): array|false
    {
        return $this->objects($reference, $pattern, subscribedOnly: false);
    }

    /**
     * @return \stdClass[]|false
     */
    public function getSubscribed(string $reference, string $pattern): array|false
    {
        return $this->objects($reference, $pattern, subscribedOnly: true);
    }

    /**
     * @return string[]|false
     */
    private function names(string $reference, string $pattern, bool $subscribedOnly): array|false
    {
        $this->connection->ensureOpen();

        $ref = MailboxReference::parse($reference);
        $folders = $this->foldersMatching($ref, $pattern, $subscribedOnly);

        if ($folders === false || $folders === []) {
            return false;
        }

        return array_map(static fn (string $name): string => $ref->displayPrefix.$name, array_keys($folders));
    }

    /**
     * @return \stdClass[]|false
     */
    private function objects(string $reference, string $pattern, bool $subscribedOnly): array|false
    {
        $this->connection->ensureOpen();

        $flagBits = [
            '\noinferiors' => LATT_NOINFERIORS,
            '\noselect' => LATT_NOSELECT,
            '\marked' => LATT_MARKED,
            '\unmarked' => LATT_UNMARKED,
            '\haschildren' => LATT_HASCHILDREN,
            '\hasnochildren' => LATT_HASNOCHILDREN,
        ];

        $ref = MailboxReference::parse($reference);
        $folders = $this->foldersMatching($ref, $pattern, $subscribedOnly);

        if ($folders === false || $folders === []) {
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

    /**
     * @return array<string, array<string, mixed>>|false
     */
    private function foldersMatching(MailboxReference $ref, string $pattern, bool $subscribedOnly): array|false
    {
        try {
            return $subscribedOnly
                ? $this->connection->protocol()->subscribedFolders($ref->bareReference, $pattern)
                : $this->connection->protocol()->folders($ref->bareReference, $pattern);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }
    }

    public function status(string $mailbox, int $flags): \stdClass|false
    {
        $this->connection->ensureOpen();

        if (($flags & ~SA_ALL) !== 0) {
            throw new \ValueError('imap_status(): Argument #3 ($flags) must be a bitmask of SA_* constants');
        }

        $itemBits = [
            'MESSAGES' => SA_MESSAGES,
            'RECENT' => SA_RECENT,
            'UNSEEN' => SA_UNSEEN,
            'UIDNEXT' => SA_UIDNEXT,
            'UIDVALIDITY' => SA_UIDVALIDITY,
        ];

        $items = array_keys(array_filter($itemBits, static fn (int $bit): bool => (bool) ($flags & $bit)));
        $folderName = MailboxReference::parse($mailbox)->bareReference;

        try {
            $status = $this->connection->protocol()->folderStatus($folderName, $items);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $result = new \stdClass();
        $result->flags = 0;
        foreach ($itemBits as $item => $bit) {
            $key = strtolower($item);
            if (isset($status[$key])) {
                $result->flags |= $bit;
                $result->{$key} = $status[$key];
            }
        }

        return $result;
    }

    /**
     * The quota root is sent verbatim (c-client passes it as a plain
     * ASTRING), so unlike status()/append() there is no {host} prefix
     * parsing here.
     *
     * @return array<string, int|array<string, int>>|false
     */
    public function getQuota(string $quotaRoot): array|false
    {
        $this->connection->ensureOpen();

        try {
            $resources = $this->connection->protocol()->getQuota($quotaRoot);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return $this->quotaArray($resources);
    }

    /**
     * @return array<string, int|array<string, int>>|false
     */
    public function getQuotaRoot(string $mailbox): array|false
    {
        $this->connection->ensureOpen();

        try {
            $resources = $this->connection->protocol()->getQuotaRoot($mailbox);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return $this->quotaArray($resources);
    }

    public function setQuota(string $quotaRoot, int $mailboxSize): bool
    {
        $this->connection->ensureOpen();

        try {
            $this->connection->protocol()->setQuota($quotaRoot, $mailboxSize);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * php_imap.c's mail_getquota callback: every resource gets its own
     * ['usage', 'limit'] entry, and any resource whose name merely *starts*
     * with STORAGE also feeds the top-level usage/limit legacy keys.
     *
     * @param array<int, array{name: string, usage: int, limit: int}> $resources
     *
     * @return array<string, int|array<string, int>>
     */
    private function quotaArray(array $resources): array
    {
        $result = [];
        foreach ($resources as $resource) {
            if (str_starts_with($resource['name'], 'STORAGE')) {
                $result['usage'] = $resource['usage'];
                $result['limit'] = $resource['limit'];
            }
            $result[$resource['name']] = ['usage' => $resource['usage'], 'limit' => $resource['limit']];
        }

        return $result;
    }

    public function createMailbox(string $mailbox): bool
    {
        $this->connection->ensureOpen();

        $folderName = MailboxReference::parse($mailbox)->bareReference;

        try {
            $this->connection->createFolder($folderName);
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
            $this->connection->deleteFolder($folderName);
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
            $this->connection->renameFolder($fromName, $toName);
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
            $this->connection->subscribeFolder($folderName);
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
            $this->connection->unsubscribeFolder($folderName);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }
}
