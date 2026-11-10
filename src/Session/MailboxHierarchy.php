<?php

namespace ImapPolyfill\Session;

use ImapPolyfill\Connection\ListedFolder;
use ImapPolyfill\Mailbox\MailboxReference;
use ImapPolyfill\Mailbox\MalformedSpec;
use ImapPolyfill\Mailbox\Service;
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

        $listing = $this->listing($reference, $pattern);

        if ($listing === null) {
            return false;
        }

        [$ref, $pattern] = $listing;
        $folders = $this->foldersMatching($ref, $pattern, $subscribedOnly);

        if ($folders === false || $folders === []) {
            return false;
        }

        return array_map(static fn (ListedFolder $folder): string => $ref->displayPrefix.$folder->name, $folders);
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

        $listing = $this->listing($reference, $pattern);

        if ($listing === null) {
            return false;
        }

        [$ref, $pattern] = $listing;
        $folders = $this->foldersMatching($ref, $pattern, $subscribedOnly);

        if ($folders === false || $folders === []) {
            return false;
        }

        $result = [];
        foreach ($folders as $folder) {
            // imap_parse_unsolicited() knows these six and ignores every
            // other attribute, extensions included.
            $attributes = 0;
            foreach ($folder->attributes as $attribute) {
                $attributes |= $flagBits[strtolower($attribute)] ?? 0;
            }

            $mailbox = new \stdClass();
            $mailbox->name = $ref->displayPrefix.$folder->name;
            $mailbox->attributes = $attributes;
            // php_imap.c writes the delimiter as ZSTR_CHAR() of c-client's
            // char, which a NIL delimiter leaves at '\0': a one-byte string
            // holding a NUL, not an empty one.
            $mailbox->delimiter = $folder->delimiter ?? "\0";
            $result[] = $mailbox;
        }

        return $result;
    }

    /**
     * The reference and pattern as LIST/LSUB send them. A pattern carrying
     * its own "{host}" prefix overrides the reference (mail_list() drops it),
     * and the prefix then comes off the pattern instead. Null where that
     * prefix is one the connection's driver refuses, which imap_list_work()
     * answers by sending nothing and logging nothing.
     *
     * @return array{MailboxReference, string}|null
     */
    private function listing(string $reference, string $pattern): ?array
    {
        try {
            if (str_starts_with($pattern, '{')) {
                $spec = MailboxReference::parse($pattern, $this->service());

                return [new MailboxReference($spec->displayPrefix, ''), $spec->bareReference];
            }

            return [MailboxReference::parse($reference, $this->service()), $pattern];
        } catch (MalformedSpec) {
            return null;
        }
    }

    /**
     * The folder name to send for $mailbox, or null where its "{host}"
     * prefix is one c-client refuses. mail_valid() logs that refusal only
     * when its caller names a purpose; mail_create() and the new name of
     * mail_rename() give none, and fail silently.
     */
    private function folderName(string $mailbox, ?string $purpose): ?string
    {
        try {
            return MailboxReference::parse($mailbox, $this->service())->bareReference;
        } catch (MalformedSpec) {
            if ($purpose !== null) {
                ErrorStack::push(MalformedSpec::of($mailbox, $purpose)->getMessage());
            }

            return null;
        }
    }

    private function service(): Service
    {
        return Service::from($this->connection->backend()->driverName());
    }

    /**
     * @return list<ListedFolder>|false
     */
    private function foldersMatching(MailboxReference $ref, string $pattern, bool $subscribedOnly): array|false
    {
        try {
            return $subscribedOnly
                ? $this->connection->backend()->subscribedFolders($ref->bareReference, $pattern)
                : $this->connection->backend()->folders($ref->bareReference, $pattern);
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
        $folderName = $this->folderName($mailbox, 'get status of mailbox');

        if ($folderName === null) {
            return false;
        }

        try {
            $status = $this->connection->backend()->folderStatus($folderName, $items);
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
     * @return array<string, string>|false identifier => rights
     */
    public function getAcl(string $mailbox): array|false
    {
        $this->connection->ensureOpen();

        try {
            return $this->connection->backend()->getAcl($mailbox);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }
    }

    public function setAcl(string $mailbox, string $id, string $rights): bool
    {
        $this->connection->ensureOpen();

        return $this->attempt(fn () => $this->connection->backend()->setAcl($mailbox, $id, $rights));
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
        return $this->quotaReported(fn () => $this->connection->backend()->getQuota($quotaRoot));
    }

    /**
     * @return array<string, int|array<string, int>>|false
     */
    public function getQuotaRoot(string $mailbox): array|false
    {
        return $this->quotaReported(fn () => $this->connection->backend()->getQuotaRoot($mailbox));
    }

    /**
     * @param \Closure(): array<int, array{name: string, usage: int, limit: int}> $resources
     *
     * @return array<string, int|array<string, int>>|false
     */
    private function quotaReported(\Closure $resources): array|false
    {
        $this->connection->ensureOpen();

        try {
            $reported = $resources();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return $this->quotaArray($reported);
    }

    public function setQuota(string $quotaRoot, int $mailboxSize): bool
    {
        $this->connection->ensureOpen();

        return $this->attempt(fn () => $this->connection->backend()->setQuota($quotaRoot, $mailboxSize));
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
        $folderName = $this->folderName($mailbox, null);

        return $folderName !== null
            && $this->attempt(fn () => $this->connection->backend()->createFolder($folderName));
    }

    public function deleteMailbox(string $mailbox): bool
    {
        $this->connection->ensureOpen();
        $folderName = $this->folderName($mailbox, 'delete mailbox');

        return $folderName !== null
            && $this->attempt(fn () => $this->connection->backend()->deleteFolder($folderName));
    }

    public function renameMailbox(string $from, string $to): bool
    {
        $this->connection->ensureOpen();
        $fromName = $this->folderName($from, 'rename mailbox');
        $toName = $fromName !== null ? $this->folderName($to, null) : null;

        return $fromName !== null && $toName !== null
            && $this->attempt(fn () => $this->connection->backend()->renameFolder($fromName, $toName));
    }

    public function subscribe(string $mailbox): bool
    {
        $this->connection->ensureOpen();
        $folderName = $this->folderName($mailbox, 'subscribe to mailbox');

        return $folderName !== null
            && $this->attempt(fn () => $this->connection->backend()->subscribeFolder($folderName));
    }

    public function unsubscribe(string $mailbox): bool
    {
        $this->connection->ensureOpen();
        $folderName = $this->folderName($mailbox, null);

        if ($folderName === null) {
            // mail_unsubscribe() finds no driver for the spec and hands the
            // name to sm_unsubscribe(), c-client's local subscription file,
            // which says this when there is no such file — and here there
            // never is one.
            ErrorStack::push('No subscriptions');

            return false;
        }

        return $this->attempt(fn () => $this->connection->backend()->unsubscribeFolder($folderName));
    }

    /**
     * The whole of what the folder-mutating functions do with a failure:
     * the server's own words go on the error stack and the caller is told
     * false, which is all php_imap.c reports of them either.
     *
     * @param \Closure(): void $operation
     */
    private function attempt(\Closure $operation): bool
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }
}
