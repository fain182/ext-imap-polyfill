<?php

namespace ImapPolyfill\Mailbox;

/**
 * Parses ext-imap's mailbox specification syntax, e.g.
 * "{imap.example.com:993/ssl}INBOX": the server to connect to (host, port,
 * connection flags like ssl/tls/novalidate-cert) and the folder to select
 * once connected. Used by imap_open() and imap_reopen().
 */
final class MailboxSpec
{
    /**
     * @param string[] $flags
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly array $flags,
        public readonly string $folder,
    ) {
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /**
     * @throws \ValueError when the string is not a "{host...}folder" spec
     */
    public static function parse(string $mailbox): self
    {
        if (!preg_match('/^\{([^}]+)\}(.*)$/', $mailbox, $matches)) {
            throw new \ValueError(
                "Malformed mailbox specification \"{$mailbox}\": expected \"{host[:port][/flag...]}folder\""
            );
        }

        // c-client treats an omitted folder part as INBOX: imap_open("{host}")
        // selects INBOX rather than leaving no mailbox selected.
        $folder = $matches[2] !== '' ? $matches[2] : 'INBOX';
        $parts = explode('/', $matches[1]);
        $hostPort = array_shift($parts);
        $flags = $parts;

        if (str_contains($hostPort, ':')) {
            [$host, $port] = explode(':', $hostPort, 2);
            $port = (int) $port;
        } else {
            // c-client picks the default port per service: IMAP 143 (993
            // over SSL), POP3 110 (995 over SSL).
            $host = $hostPort;
            $ssl = in_array('ssl', $flags, true);
            $port = in_array('pop3', $flags, true)
                ? ($ssl ? 995 : 110)
                : ($ssl ? 993 : 143);
        }

        return new self($host, $port, $flags, $folder);
    }
}
