<?php

namespace ImapPolyfill\Mailbox;

/**
 * What c-client's mail_valid() logs when no driver accepts a "{host}folder"
 * spec — a malformed one, a switch outside the closed set, or a service that
 * names no remote driver. All three read the same to the caller, and the
 * wrappers turn this into imap_open()'s false plus the stack message.
 *
 * A name with no "{" at all is a different complaint. c-client hands that to
 * the local drivers, which look for a file of that name and report "no such
 * mailbox" when there isn't one — and there never is one here, this package
 * having no local drivers to hand it to.
 */
final class MalformedSpec extends \ValueError
{
    /**
     * @param string $purpose mail_valid()'s purpose argument, which names the
     *                        operation that refused the spec
     */
    public static function of(string $mailbox, string $purpose = 'open mailbox'): self
    {
        // "Can't %s %.80s: %s" in mail.c: the spec is truncated at 80 bytes,
        // however long it was.
        return new self(
            "Can't ".$purpose.' '.substr($mailbox, 0, 80).': '
            .(str_starts_with($mailbox, '{') ? 'invalid remote specification' : 'no such mailbox')
        );
    }
}
