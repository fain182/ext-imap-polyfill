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
     * @param string   $charset  how the server should read the criteria's
     *                           bytes; empty when the caller named none
     *
     * @return int[]
     */
    public function search(array $tokens, int $uidMode, string $charset = ''): array;

    public function hasCapability(string $capability): bool;

    /**
     * Server-side sort. Returns null when the server has no usable SORT (it
     * rejected the command, or the protocol has none at all), which is
     * c-client's cue to sort locally instead.
     *
     * @param string $program the sort program inside the parentheses, e.g. "REVERSE DATE"
     * @param string[] $searchTokens the search program the results are drawn from
     *
     * @return int[]|null
     */
    public function sort(string $program, string $charset, array $searchTokens, int $uidMode): ?array;

    /**
     * Server-side threading. Returns the nested id groups of the THREAD
     * response, or null when the server has no usable threader — c-client's
     * cue to thread locally instead.
     *
     * @param string[] $searchTokens
     *
     * @return array<int, mixed>|null
     */
    public function thread(string $algorithm, string $charset, array $searchTokens, int $uidMode): ?array;

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

    public function getMessageNumber(string $uid): int;

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
     * Asks the server to report the selected folder's state, the way
     * c-client's CHECK does. Unlike the other reads this one must reach the
     * server: imap_check() is a live query.
     */
    public function check(): void;

    /**
     * @param string[] $items
     *
     * @return array<string, int>
     */
    public function folderStatus(string $folder, array $items): array;

    /**
     * @return array<string, string> identifier => rights
     */
    public function getAcl(string $mailbox): array;

    public function setAcl(string $mailbox, string $id, string $rights): void;

    /**
     * @return array<int, array{name: string, usage: int, limit: int}>
     */
    public function getQuota(string $quotaRoot): array;

    /**
     * @return array<int, array{name: string, usage: int, limit: int}>
     */
    public function getQuotaRoot(string $mailbox): array;

    public function setQuota(string $quotaRoot, int $mailboxSize): void;
}
