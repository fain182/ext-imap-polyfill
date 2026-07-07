<?php

namespace ImapPolyfill\Connection;

/**
 * The wire operations an \IMAP\Connection needs from whatever protocol it
 * actually speaks. IMAP\Connection owns connection-level state (selected
 * folder, read-only flag, cached counters) and is otherwise a thin delegator
 * to one implementation of this interface per protocol (see
 * Connection\Imap\ImapBackend, Connection\Pop3\Pop3Backend).
 */
interface ConnectionBackend
{
    /** The value ext-imap reports as imap_check()'s stdClass->Driver. */
    public function driverName(): string;

    /**
     * @return array<string, mixed>
     */
    public function selectOrExamineFolder(string $folder, bool $readOnly): array;

    public function host(): string;

    public function expunge(): void;

    public function disconnect(): void;

    public function createFolder(string $name): void;

    public function deleteFolder(string $name): void;

    public function renameFolder(string $from, string $to): void;

    public function subscribeFolder(string $name): void;

    public function unsubscribeFolder(string $name): void;

    /**
     * @param string[]|null $flags
     */
    public function appendMessage(string $folder, string $message, ?array $flags, ?string $internalDate): void;

    /**
     * @return array<int, mixed>
     */
    public function fetchBodyStructure(int $messageNum, bool $byUid): array;

    /**
     * @param string[] $tokens
     *
     * @return int[]
     */
    public function search(array $tokens, int $uidMode): array;

    /**
     * @param int[] $ids
     *
     * @return array<int, string>
     */
    public function headers(array $ids, string $type, int $uidMode): array;

    /**
     * A single requested item collapses to its scalar value per id instead
     * of a one-key array; multiple items return an item-name-keyed array.
     *
     * @param string[] $items
     * @param int[] $ids
     *
     * @return array<int, mixed>
     */
    public function fetch(array $items, array $ids, ?int $to, int $uidMode): array;

    /**
     * @return array<int, int>
     */
    public function getUid(): array;

    public function getMessageNumber(string $uid): int|string;

    /**
     * @param string[] $args
     */
    public function store(string $command, array $args): void;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function folders(string $reference, string $pattern): array;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function subscribedFolders(string $reference, string $pattern): array;

    public function copy(string $sequence, string $folder, int $uidMode): void;

    public function noop(): void;

    /**
     * @param string[] $items
     *
     * @return array<string, int>
     */
    public function folderStatus(string $folder, array $items): array;
}
