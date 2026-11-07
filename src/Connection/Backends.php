<?php

namespace ImapPolyfill\Connection;

use ImapPolyfill\Connection\Pop3\Pop3Backend;
use ImapPolyfill\Connection\Pop3\Pop3Protocol;
use ImapPolyfill\Mailbox\MailboxSpec;
use ImapPolyfill\Support\Timeouts;

/**
 * A logged-in ConnectionBackend for each protocol, so that the imap_* layer
 * opens one without naming the implementations. Each attempt either answers
 * a backend or throws; retrying and reporting are the caller's, as they are
 * part of imap_open()'s contract.
 */
final class Backends
{
    public static function imap(MailboxSpec $spec, Credentials $credentials): ConnectionBackend
    {
        return Protocol::dial($spec, $credentials);
    }

    public static function pop3(MailboxSpec $spec, string $mailbox, Credentials $credentials): ConnectionBackend
    {
        $protocol = Pop3Protocol::dial(
            $spec->host,
            $spec->port,
            $spec->encryption(),
            $spec->switches->notls,
            !$spec->switches->novalidate,
            (float) Timeouts::seconds(IMAP_OPENTIMEOUT),
            (float) Timeouts::socketSeconds(),
        );

        // No CAPA: pop3_auth() checks these before looking at what
        // the server offers, and this backend has only USER/PASS.
        $credentials->assertPlaintextLoginAllowed();
        $protocol->login($credentials->user, $credentials->password);

        return new Pop3Backend($protocol, $spec->host, $mailbox);
    }
}
