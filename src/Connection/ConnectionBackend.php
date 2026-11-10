<?php

namespace ImapPolyfill\Connection;

use ImapPolyfill\Message\BodyPart;

use ImapPolyfill\Message\SearchProgram;

/**
 * The wire operations an \IMAP\Connection needs from whatever protocol it
 * actually speaks. IMAP\Connection owns connection-level state (selected
 * folder, read-only flag, half-open) and is otherwise a thin delegator
 * to one implementation of this interface per protocol (see
 * Connection\Protocol, Connection\Pop3\Pop3Backend).
 */
interface ConnectionBackend
{
    /** The value ext-imap reports as imap_check()'s stdClass->Driver. */
    public function driverName(): string;

    /**
     * Whether c-client's driver for this protocol has an overview
     * (DRIVER->overview), which decides where its local threading reads a
     * message's references from.
     */
    public function hasOverview(): bool;

    public function selectOrExamineFolder(string $folder, bool $readOnly): FolderState;

    /**
     * The same selection, made again rather than remembered. Only
     * imap_reopen() asks for this: everything else is happy with whatever
     * is already selected.
     */
    public function reselectFolder(string $folder, bool $readOnly): FolderState;

    /**
     * Leaves the selected folder without expunging it, which is RFC 3691's
     * UNSELECT. Sent only where hasCapability('UNSELECT') said so.
     */
    public function unselect(): void;

    public function host(): string;

    /**
     * Whether the connection reached TLS by upgrading a cleartext one
     * (STARTTLS/STLS) rather than being encrypted from its first byte.
     * c-client reports the upgrade it negotiated in stream->mailbox, so a
     * spec carrying no TLS switch at all can still answer "/tls".
     */
    public function upgradedToTls(): bool;

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

    public function fetchBodyStructure(int $messageNum, bool $byUid): BodyPart;

    /**
     * @param SearchProgram $program the parsed criteria; the IMAP backend writes
     *                               a SEARCH from them, the POP3 one evaluates them
     * @param string|null   $charset how the criteria's bytes are to be read;
     *                               null when the caller named none, which is
     *                               not the same as naming ''
     *
     * @return int[]
     */
    public function search(SearchProgram $program, UidMode $uidMode, ?string $charset = null): array;

    public function hasCapability(string $capability): bool;

    /**
     * Server-side sort. Returns null when the server has no usable SORT (it
     * rejected the command, or the protocol has none at all), which is
     * c-client's cue to sort locally instead.
     *
     * @param string $program the sort program inside the parentheses, e.g. "REVERSE DATE"
     * @param SearchProgram|null $search the search program the results are
     *                                  drawn from; null for every message
     *
     * @return int[]|null
     */
    public function sort(string $program, string $charset, ?SearchProgram $search, UidMode $uidMode): ?array;

    /**
     * Server-side threading. Returns the nested id groups of the THREAD
     * response, or null when the server has no usable threader — c-client's
     * cue to thread locally instead.
     *
     * @param SearchProgram|null $search null for every message
     *
     * @return array<int, mixed>|null
     */
    public function thread(string $algorithm, string $charset, ?SearchProgram $search, UidMode $uidMode): ?array;

    /**
     * A single requested item collapses to its scalar value per id instead
     * of a one-key array; multiple items return an item-name-keyed array.
     *
     * @param string[] $items
     * @param int[] $ids
     *
     * @return array<int, mixed>
     */
    public function fetch(array $items, array $ids, ?int $to, UidMode $uidMode): array;

    /**
     * The selected folder's uids, which every question about a uid — which
     * message it names, whether the folder has it, what "*" stands for — is
     * asked of.
     */
    public function uidTable(): UidTable;

    /**
     * @param string[] $args
     */
    public function store(string $command, array $args): void;

    /**
     * @return list<ListedFolder>
     */
    public function folders(string $reference, string $pattern): array;

    /**
     * @return list<ListedFolder>
     */
    public function subscribedFolders(string $reference, string $pattern): array;

    public function copy(string $sequence, string $folder, UidMode $uidMode): void;

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
