<?php

namespace ImapPolyfill\Mail;

/**
 * The body of imap_mail(): a line-for-line port of php_imap.c's
 * _php_imap_mail() Unix path — validate, then pipe the hand-assembled
 * header block and body into sendmail_path. Deliberately NOT delegated to
 * mail(): mail() rewrites header order, terminates lines with CRLF, and
 * enforces its own $additional_headers policy, all of which would diverge
 * from the real extension's verbatim LF-terminated output. The real
 * extension's Windows build spoke SMTP (TSendMail) instead; this port is
 * pipe-only (see README).
 */
final class OutgoingMail
{
    public static function send(
        string $to,
        string $subject,
        string $message,
        ?string $additionalHeaders,
        ?string $cc,
        ?string $bcc,
        ?string $returnPath,
    ): bool {
        if ($to === '') {
            throw new \ValueError('imap_mail(): Argument #1 ($to) cannot be empty');
        }
        if ($subject === '') {
            throw new \ValueError('imap_mail(): Argument #2 ($subject) cannot be empty');
        }
        if ($message === '') {
            // Allowed, like the real extension — warn and send anyway.
            trigger_error('imap_mail(): No message string in mail command', E_USER_WARNING);
        }

        $sendmailPath = ini_get('sendmail_path');
        if ($sendmailPath === false || $sendmailPath === '') {
            return false;
        }

        $sendmail = @popen($sendmailPath, 'w');
        if ($sendmail === false) {
            trigger_error('imap_mail(): Could not execute mail delivery program', E_USER_WARNING);

            return false;
        }

        if ($returnPath !== null && $returnPath !== '') {
            // ext-imap writes the return path as a literal From: header,
            // not as the envelope sender.
            fwrite($sendmail, "From: {$returnPath}\n");
        }
        fwrite($sendmail, "To: {$to}\n");
        if ($cc !== null && $cc !== '') {
            fwrite($sendmail, "Cc: {$cc}\n");
        }
        if ($bcc !== null && $bcc !== '') {
            fwrite($sendmail, "Bcc: {$bcc}\n");
        }
        fwrite($sendmail, "Subject: {$subject}\n");
        if ($additionalHeaders !== null && $additionalHeaders !== '') {
            fwrite($sendmail, "{$additionalHeaders}\n");
        }
        fwrite($sendmail, "\n{$message}\n");

        return pclose($sendmail) !== -1;
    }
}
