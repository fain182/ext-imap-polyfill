<?php

namespace ImapPolyfill\Mailbox;

/**
 * The remote drivers a "{host}folder" spec can name.
 *
 * Nntp is here to be refused, not served: Session::open() throws
 * UnsupportedFeature for it before anything is dialed, so that a spec naming
 * NNTP fails loudly instead of being opened over IMAP. This package does not
 * speak NNTP.
 *
 * c-client's parser accepts more service names than these — /smtp, /submit and
 * any /service=whatever — but none of them names a driver that can open a
 * remote mailbox, so mail_valid() rejects the spec a moment later with the
 * same "invalid remote specification" it gives a malformed one. MailboxSpec
 * therefore refuses them while parsing: the caller sees the identical error.
 */
enum Service: string
{
    case Imap = 'imap';
    case Pop3 = 'pop3';
    case Nntp = 'nntp';

    /**
     * The port c-client's driver dials when the spec names none.
     */
    public function defaultPort(bool $ssl): int
    {
        return match ($this) {
            self::Imap => $ssl ? 993 : 143,
            self::Pop3 => $ssl ? 995 : 110,
            self::Nntp => $ssl ? 563 : 119,
        };
    }
}
