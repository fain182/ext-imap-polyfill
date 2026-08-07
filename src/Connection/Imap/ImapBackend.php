<?php

namespace ImapPolyfill\Connection\Imap;

use ImapPolyfill\Connection\ConnectionBackend;
use ImapPolyfill\Connection\Protocol;

/**
 * ConnectionBackend implementation backed by directorytree/imapengine. Sole
 * owner of the IMAP connection for the lifetime of an \IMAP\Connection.
 */
final class ImapBackend implements ConnectionBackend
{
    private readonly Protocol $protocol;

    public function __construct(ImapEngineConnection $connection, private readonly string $host)
    {
        $this->protocol = new Protocol($connection);
    }

    public function driverName(): string
    {
        return 'imap';
    }

    public function selectOrExamineFolder(string $folder, bool $readOnly): array
    {
        return $this->protocol->selectOrExamine($folder, $readOnly);
    }

    public function host(): string
    {
        return $this->host;
    }

    public function expunge(): void
    {
        $this->protocol->expunge();
    }

    public function disconnect(): void
    {
        $this->protocol->disconnect();
    }

    public function createFolder(string $name): void
    {
        $this->protocol->createFolder($name);
    }

    public function deleteFolder(string $name): void
    {
        $this->protocol->deleteFolder($name);
    }

    public function renameFolder(string $from, string $to): void
    {
        $this->protocol->renameFolder($from, $to);
    }

    public function subscribeFolder(string $name): void
    {
        $this->protocol->subscribeFolder($name);
    }

    public function unsubscribeFolder(string $name): void
    {
        $this->protocol->unsubscribeFolder($name);
    }

    public function appendMessage(string $folder, string $message, ?array $flags, ?string $internalDate): void
    {
        $this->protocol->appendMessage($folder, $message, $flags, $internalDate);
    }

    public function fetchBodyStructure(int $messageNum, bool $byUid): array
    {
        return $this->protocol->bodyStructure($messageNum, $byUid);
    }

    public function search(array $tokens, int $uidMode): array
    {
        return $this->protocol->search($tokens, $uidMode);
    }

    public function hasCapability(string $capability): bool
    {
        return $this->protocol->hasCapability($capability);
    }

    public function sort(string $program, string $charset, array $searchTokens, int $uidMode): ?array
    {
        return $this->protocol->sort($program, $charset, $searchTokens, $uidMode);
    }

    public function thread(string $algorithm, string $charset, array $searchTokens, int $uidMode): ?array
    {
        return $this->protocol->thread($algorithm, $charset, $searchTokens, $uidMode);
    }

    public function headers(array $ids, string $type, int $uidMode): array
    {
        return $this->protocol->headers($ids, $type, $uidMode);
    }

    public function fetch(array $items, array $ids, ?int $to, int $uidMode): array
    {
        return $this->protocol->fetch($items, $ids, $to, $uidMode);
    }

    public function getUid(): array
    {
        return $this->protocol->getUid();
    }

    public function getMessageNumber(string $uid): int
    {
        return $this->protocol->getMessageNumber($uid);
    }

    public function store(string $command, array $args): void
    {
        $this->protocol->store($command, $args);
    }

    public function folders(string $reference, string $pattern): array
    {
        return $this->protocol->folders($reference, $pattern);
    }

    public function subscribedFolders(string $reference, string $pattern): array
    {
        return $this->protocol->subscribedFolders($reference, $pattern);
    }

    public function copy(string $sequence, string $folder, int $uidMode): void
    {
        $this->protocol->copy($sequence, $folder, $uidMode);
    }

    public function noop(): void
    {
        $this->protocol->noop();
    }

    public function check(): void
    {
        $this->protocol->check();
    }

    public function folderStatus(string $folder, array $items): array
    {
        return $this->protocol->folderStatus($folder, $items);
    }

    public function getAcl(string $mailbox): array
    {
        $this->ensureAclCapability();

        return $this->protocol->getAcl($mailbox);
    }

    public function setAcl(string $mailbox, string $id, string $rights): void
    {
        $this->ensureAclCapability();
        $this->protocol->setAcl($mailbox, $id, $rights);
    }

    public function getQuota(string $quotaRoot): array
    {
        $this->ensureQuotaCapability();

        return $this->protocol->getQuota($quotaRoot);
    }

    public function getQuotaRoot(string $mailbox): array
    {
        $this->ensureQuotaCapability();

        return $this->protocol->getQuotaRoot($mailbox);
    }

    public function setQuota(string $quotaRoot, int $mailboxSize): void
    {
        $this->ensureQuotaCapability();
        $this->protocol->setQuota($quotaRoot, $mailboxSize);
    }

    /**
     * c-client's LEVELACL gate (imap_acl_work), the ACL twin of the quota
     * one below, down to the message it logs.
     */
    private function ensureAclCapability(): void
    {
        if (!$this->hasCapability('ACL')) {
            throw new \RuntimeException('ACL not available on this IMAP server');
        }
    }

    /**
     * c-client's LEVELQUOTA gate: without the capability no command is sent
     * and this exact message lands on the error stack.
     */
    private function ensureQuotaCapability(): void
    {
        if (!$this->hasCapability('QUOTA')) {
            throw new \RuntimeException('Quota not available on this IMAP server');
        }
    }
}
