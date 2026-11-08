<?php

namespace ImapPolyfill\Mailbox;

/**
 * A mailbox name as the folder-level functions take it — list/lsub's
 * reference or pattern, the folder of status, append, create and the rest —
 * split into the "{host...}" display prefix and the bare name that goes on
 * the wire. The prefix is re-prepended to every folder LIST answers, so
 * results read like full mailbox specs.
 *
 * c-client only takes the prefix off a name the stream's own driver
 * accepts: imap_valid() and pop3_valid() are mail_valid_net_parse() plus
 * "the service is mine", mail_valid() refuses a driver other than the
 * stream's, and the prefix is then everything up to the first "}"
 * (imap_list_work() in imap4r1.c). A name that starts with "{" and fails
 * that is not a folder whose name happens to begin with a brace — no driver
 * serves it, and the operation fails before anything reaches the server.
 */
final class MailboxReference
{
    public function __construct(
        public readonly string $displayPrefix,
        public readonly string $bareReference,
    ) {
    }

    /**
     * @param Service $service the service of the connection the name is for
     *
     * @throws MalformedSpec when the name starts with "{" and is not a spec
     *                       for that service
     */
    public static function parse(string $reference, Service $service): self
    {
        if (!str_starts_with($reference, '{')) {
            return new self('', $reference);
        }

        if (MailboxSpec::parse($reference)->service !== $service) {
            throw MalformedSpec::of($reference);
        }

        $prefixLength = (int) strpos($reference, '}') + 1;

        return new self(substr($reference, 0, $prefixLength), substr($reference, $prefixLength));
    }
}
