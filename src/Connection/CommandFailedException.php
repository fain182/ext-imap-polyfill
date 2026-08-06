<?php

namespace ImapPolyfill\Connection;

/**
 * A command the server refused. The message is the server's own response
 * text — no tag, no status, no echo of the command — because that is what
 * c-client hands to mm_log(), and so what imap_errors()/imap_last_error()
 * report.
 */
final class CommandFailedException extends \RuntimeException
{
    /**
     * @param string $status NO (the command failed) or BAD (the server didn't understand it)
     */
    public function __construct(private readonly string $status, string $text)
    {
        parent::__construct($text);
    }

    public function status(): string
    {
        return $this->status;
    }
}
