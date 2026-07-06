<?php

namespace ImapPolyfill\Mailbox;

/**
 * Splits a LIST/LSUB reference argument into the "{host...}" display prefix
 * (stripped before sending the LIST command to the server, then re-prepended
 * to each returned folder name so results read like full mailbox specs) and
 * the bare reference text actually sent on the wire.
 */
final class MailboxReference
{
    public function __construct(
        public readonly string $displayPrefix,
        public readonly string $bareReference,
    ) {
    }

    public static function parse(string $reference): self
    {
        if (preg_match('/^(\{[^}]+\})(.*)$/', $reference, $matches)) {
            return new self($matches[1], $matches[2]);
        }

        return new self('', $reference);
    }
}
