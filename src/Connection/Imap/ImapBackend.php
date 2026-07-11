<?php

namespace ImapPolyfill\Connection\Imap;

use ImapPolyfill\Connection\ConnectionBackend;
use ImapPolyfill\Connection\Protocol;
use ImapPolyfill\Message\BodyStructureFetch;

/**
 * ConnectionBackend implementation backed by webklex/php-imap. Sole owner of
 * the webklex Client for the lifetime of an \IMAP\Connection.
 */
final class ImapBackend implements ConnectionBackend
{
    private ?Protocol $protocol = null;

    public function __construct(private readonly \Webklex\PHPIMAP\Client $client)
    {
    }

    public function driverName(): string
    {
        return 'imap';
    }

    public function selectOrExamineFolder(string $folder, bool $readOnly): array
    {
        $folderObj = $this->client->getFolder($folder);

        return $readOnly ? $folderObj->examine() : $folderObj->select();
    }

    public function host(): string
    {
        return $this->client->host;
    }

    public function expunge(): void
    {
        $this->client->expunge();
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
    }

    public function createFolder(string $name): void
    {
        $this->client->createFolder($name);
    }

    public function deleteFolder(string $name): void
    {
        $this->client->deleteFolder($name);
    }

    public function renameFolder(string $from, string $to): void
    {
        $this->client->getFolder($from)->rename($to);
    }

    public function subscribeFolder(string $name): void
    {
        $this->client->getFolder($name)->subscribe();
    }

    public function unsubscribeFolder(string $name): void
    {
        $this->client->getFolder($name)->unsubscribe();
    }

    public function appendMessage(string $folder, string $message, ?array $flags, ?string $internalDate): void
    {
        $this->client->getFolder($folder)->appendMessage($message, $flags, $internalDate);
    }

    public function fetchBodyStructure(int $messageNum, bool $byUid): array
    {
        return BodyStructureFetch::fetch($this->client, $messageNum, $byUid);
    }

    public function search(array $tokens, int $uidMode): array
    {
        return $this->protocol()->search($tokens, $uidMode);
    }

    public function headers(array $ids, string $type, int $uidMode): array
    {
        return $this->protocol()->headers($ids, $type, $uidMode);
    }

    public function fetch(array $items, array $ids, ?int $to, int $uidMode): array
    {
        return $this->protocol()->fetch($items, $ids, $to, $uidMode);
    }

    public function getUid(): array
    {
        return $this->protocol()->getUid();
    }

    public function getMessageNumber(string $uid): int|string
    {
        return $this->protocol()->getMessageNumber($uid);
    }

    public function store(string $command, array $args): void
    {
        $this->protocol()->store($command, $args);
    }

    public function folders(string $reference, string $pattern): array
    {
        return $this->protocol()->folders($reference, $pattern);
    }

    public function subscribedFolders(string $reference, string $pattern): array
    {
        return $this->protocol()->subscribedFolders($reference, $pattern);
    }

    public function copy(string $sequence, string $folder, int $uidMode): void
    {
        $this->protocol()->copy($sequence, $folder, $uidMode);
    }

    public function noop(): void
    {
        $this->protocol()->noop();
    }

    public function folderStatus(string $folder, array $items): array
    {
        return $this->protocol()->folderStatus($folder, $items);
    }

    public function getQuota(string $quotaRoot): array
    {
        $this->ensureQuotaCapability();

        return $this->protocol()->getQuota($quotaRoot);
    }

    public function getQuotaRoot(string $mailbox): array
    {
        $this->ensureQuotaCapability();

        return $this->protocol()->getQuotaRoot($mailbox);
    }

    public function setQuota(string $quotaRoot, int $mailboxSize): void
    {
        $this->ensureQuotaCapability();
        $this->protocol()->setQuota($quotaRoot, $mailboxSize);
    }

    /**
     * c-client's LEVELQUOTA gate: without the capability no command is sent
     * and this exact message lands on the error stack.
     */
    private function ensureQuotaCapability(): void
    {
        if (!$this->protocol()->hasCapability('QUOTA')) {
            throw new \RuntimeException('Quota not available on this IMAP server');
        }
    }

    private function protocol(): Protocol
    {
        return $this->protocol ??= new Protocol($this->client);
    }
}
