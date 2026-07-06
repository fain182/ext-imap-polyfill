<?php

namespace Fain182\ImapPolyfill;

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

    public static function parse(string $mailbox): self
    {
        preg_match('/^\{([^}]+)\}(.*)$/', $mailbox, $matches);

        $folder = $matches[2];
        $parts = explode('/', $matches[1]);
        $hostPort = array_shift($parts);
        $flags = $parts;

        if (str_contains($hostPort, ':')) {
            [$host, $port] = explode(':', $hostPort, 2);
            $port = (int) $port;
        } else {
            $host = $hostPort;
            $port = in_array('ssl', $flags, true) ? 993 : 143;
        }

        return new self($host, $port, $flags, $folder);
    }
}
