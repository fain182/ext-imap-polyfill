<?php

namespace ImapPolyfill\Session;

use ImapPolyfill\Mailbox\MailboxReference;
use ImapPolyfill\Support\ErrorStack;

/**
 * The mailbox hierarchy reachable from an open \IMAP\Connection: listing,
 * creating, deleting, renaming, and (un)subscribing to folders, without any
 * of them being selected.
 */
final class Mailboxes
{
    public function __construct(private readonly \IMAP\Connection $connection)
    {
    }

    /**
     * @return string[]|false
     */
    public function listMailboxes(string $reference, string $pattern): array|false
    {
        $this->connection->ensureOpen();

        $ref = MailboxReference::parse($reference);

        try {
            $folders = $this->connection->protocol()->folders($ref->bareReference, $pattern);
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
            $folders = $this->connection->protocol()->folders($ref->bareReference, $pattern);
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
