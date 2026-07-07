<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Pins every implemented function's parameter names and optionality to
 * php_imap.stub.php, so PHP 8 named arguments (imap_open(mailbox: ...))
 * keep working when user code moves from the real extension to this
 * polyfill. Runs in the integration suite (no server needed) so the parity
 * job checks the fixture itself against the genuine extension's reflection.
 */
class ImapFunctionSignaturesTest extends TestCase
{
    /**
     * Derived from php-src PHP-8.3 ext/imap/php_imap.stub.php. A trailing
     * "?" marks an optional parameter (one with a default).
     *
     * @var array<string, string[]>
     */
    private const SIGNATURES = [
        'imap_open' => ['mailbox', 'user', 'password', 'flags?', 'retries?', 'options?'],
        'imap_reopen' => ['imap', 'mailbox', 'flags?', 'retries?'],
        'imap_close' => ['imap', 'flags?'],
        'imap_is_open' => ['imap'],
        'imap_num_msg' => ['imap'],
        'imap_num_recent' => ['imap'],
        'imap_headerinfo' => ['imap', 'message_num', 'from_length?', 'subject_length?'],
        'imap_rfc822_parse_headers' => ['headers', 'default_hostname?'],
        'imap_rfc822_write_address' => ['mailbox', 'hostname', 'personal'],
        'imap_rfc822_parse_adrlist' => ['string', 'default_hostname'],
        'imap_body' => ['imap', 'message_num', 'flags?'],
        'imap_fetchtext' => ['imap', 'message_num', 'flags?'],
        'imap_fetchbody' => ['imap', 'message_num', 'section', 'flags?'],
        'imap_fetchheader' => ['imap', 'message_num', 'flags?'],
        'imap_fetchstructure' => ['imap', 'message_num', 'flags?'],
        'imap_fetchmime' => ['imap', 'message_num', 'section', 'flags?'],
        'imap_bodystruct' => ['imap', 'message_num', 'section'],
        'imap_savebody' => ['imap', 'file', 'message_num', 'section?', 'flags?'],
        'imap_gc' => ['imap', 'flags'],
        'imap_expunge' => ['imap'],
        'imap_delete' => ['imap', 'message_nums', 'flags?'],
        'imap_undelete' => ['imap', 'message_nums', 'flags?'],
        'imap_check' => ['imap'],
        'imap_mail_copy' => ['imap', 'message_nums', 'mailbox', 'flags?'],
        'imap_mail_move' => ['imap', 'message_nums', 'mailbox', 'flags?'],
        'imap_createmailbox' => ['imap', 'mailbox'],
        'imap_create' => ['imap', 'mailbox'],
        'imap_renamemailbox' => ['imap', 'from', 'to'],
        'imap_rename' => ['imap', 'from', 'to'],
        'imap_deletemailbox' => ['imap', 'mailbox'],
        'imap_subscribe' => ['imap', 'mailbox'],
        'imap_unsubscribe' => ['imap', 'mailbox'],
        'imap_append' => ['imap', 'folder', 'message', 'options?', 'internal_date?'],
        'imap_ping' => ['imap'],
        'imap_base64' => ['string'],
        'imap_qprint' => ['string'],
        'imap_8bit' => ['string'],
        'imap_binary' => ['string'],
        'imap_utf8' => ['mime_encoded_text'],
        'imap_status' => ['imap', 'mailbox', 'flags'],
        'imap_mailboxmsginfo' => ['imap'],
        'imap_setflag_full' => ['imap', 'sequence', 'flag', 'options?'],
        'imap_clearflag_full' => ['imap', 'sequence', 'flag', 'options?'],
        'imap_uid' => ['imap', 'message_num'],
        'imap_msgno' => ['imap', 'message_uid'],
        'imap_list' => ['imap', 'reference', 'pattern'],
        'imap_listmailbox' => ['imap', 'reference', 'pattern'],
        'imap_lsub' => ['imap', 'reference', 'pattern'],
        'imap_listsubscribed' => ['imap', 'reference', 'pattern'],
        'imap_getsubscribed' => ['imap', 'reference', 'pattern'],
        'imap_getmailboxes' => ['imap', 'reference', 'pattern'],
        'imap_fetch_overview' => ['imap', 'sequence', 'flags?'],
        'imap_alerts' => [],
        'imap_errors' => [],
        'imap_last_error' => [],
        'imap_search' => ['imap', 'criteria', 'flags?', 'charset?'],
        'imap_utf7_decode' => ['string'],
        'imap_utf7_encode' => ['string'],
        'imap_utf8_to_mutf7' => ['string'],
        'imap_mutf7_to_utf8' => ['string'],
        'imap_mime_header_decode' => ['string'],
        'imap_timeout' => ['timeout_type', 'timeout?'],
    ];

    public function test_every_implemented_function_matches_the_stub_signature(): void
    {
        foreach (self::SIGNATURES as $function => $expected) {
            $this->assertTrue(function_exists($function), "{$function} is not defined");

            $actual = array_map(
                static fn (\ReflectionParameter $p): string => $p->getName().($p->isOptional() ? '?' : ''),
                (new \ReflectionFunction($function))->getParameters(),
            );

            $this->assertSame($expected, $actual, "{$function} signature diverges from php_imap.stub.php");
        }
    }
}
