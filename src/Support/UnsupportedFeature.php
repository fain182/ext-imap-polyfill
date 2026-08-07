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
