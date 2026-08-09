<?php

namespace ImapPolyfill\Support;

/**
 * Something the real extension does and this polyfill does not.
 *
 * Deliberately loud, and deliberately not the false-plus-error-stack that
 * every other failure here uses: those say "this attempt failed", and a
 * caller is right to retry or log them. These say "this package cannot do
 * what you are asking, and never will", which is worth finding out at the
 * first call rather than after a debugging session — the alternative being
 * a `{host/nntp}` spec that quietly connects over IMAP instead.
 */
final class UnsupportedFeature extends \RuntimeException
{
    public static function nntp(string $mailbox): self
    {
        return new self(sprintf(
            'ext-imap-polyfill does not speak NNTP, so "%s" cannot be opened.',
            $mailbox,
        ));
    }

    /**
     * Only reachable on Windows, and only with no sendmail_path to fall
     * back on: the extension's Windows build never read that ini because it
     * spoke SMTP itself, so a host configured for it has nothing this
     * package can deliver through. On Unix both send through the pipe and
     * an unset path is the same silent false in either.
     */
    public static function smtp(): self
    {
        return new self(
            'imap_mail() delivers through the sendmail_path ini, which is not set. '
            .'The Windows build of ext-imap sent mail over SMTP instead, using the '
            .'SMTP and smtp_port ini settings; ext-imap-polyfill has no SMTP client, '
            .'so sendmail_path has to name a mail delivery program.',
        );
    }

    public static function scan(string $function): self
    {
        return new self(sprintf(
            '%s() is not implemented by ext-imap-polyfill: the IMAP SCAN '
            .'command it sends was dropped from IMAP4rev1, and current '
            .'servers do not answer it.',
            $function,
        ));
    }
}
