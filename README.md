# ext-imap-polyfill

[![Tests](https://github.com/fain182/ext-imap-polyfill/actions/workflows/tests.yml/badge.svg)](https://github.com/fain182/ext-imap-polyfill/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/fain182/ext-imap-polyfill)](https://packagist.org/packages/fain182/ext-imap-polyfill)
[![PHP Version](https://img.shields.io/packagist/dependency-v/fain182/ext-imap-polyfill/php?label=php)](https://packagist.org/packages/fain182/ext-imap-polyfill)
[![License](https://img.shields.io/packagist/l/fain182/ext-imap-polyfill)](LICENSE)

A drop-in polyfill for the `imap_*` functions removed from PHP core in 8.4.

PHP 8.4 moved `ext-imap` out of core and onto PECL ([RFC](https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)). The C library behind it, c-client, has been unmaintained since 2007 and is disappearing from distributions, so the PECL package gets harder to install every release — and the alternative, rewriting against an OOP library, is a migration rather than a version bump.

This package defines the same global `imap_*` functions, backed by [directorytree/imapengine](https://github.com/DirectoryTree/ImapEngine) for IMAP and a small raw client for POP3.

## Install

```bash
composer require fain182/ext-imap-polyfill
```

No code changes. If `ext-imap` is present (e.g. you're still on PHP 8.3), the polyfill is a no-op — safe to add before you upgrade, not just after.

Requires PHP 8.1+; the dependency tree declares only `ext-json` and `ext-iconv`. (Earlier releases needed `--ignore-platform-req=ext-zip`; this one doesn't.)

The package declares `provide: ext-imap`, so other dependencies that require `ext-imap` install cleanly alongside it.

<details>
<summary>What the calling code looks like — unchanged</summary>

```php
$imap = imap_open('{imap.example.com:993/imap/ssl}INBOX', 'user@example.com', $password);

foreach (imap_search($imap, 'UNSEEN') ?: [] as $msgno) {
    $overview = imap_fetch_overview($imap, (string) $msgno)[0];
    echo "{$overview->from}: {$overview->subject}\n";
}

imap_close($imap);
```

</details>

## Compatibility

**72 of 75** `imap_*` functions are implemented. The missing three are the SCAN family (`imap_scan`, `imap_scanmailbox`, `imap_listscan`) — a command dropped from IMAP4rev1 that in practice only UW-IMAP ever spoke, so there is nothing left to characterize it against. Calling them hits PHP's "undefined function" error, same as before this package existed.

Shapes and behaviour are checked against the real extension ([how](#verifying-against-real-ext-imap)). Anything not listed here matches it:

| Function | Divergence |
|---|---|
| `imap_check`, `imap_mailboxmsginfo` | the `Mailbox` host stays as written in the spec; c-client resolves it to its canonical DNS name |
| `imap_mail` | always delivers through the `sendmail_path` pipe, and returns false when that ini is empty |
| `imap_mail_compose` | address lists use the simplified parser of `imap_rfc822_parse_adrlist`: no group or route syntax |
| `imap_open` | only `OP_READONLY` and `CL_EXPUNGE` act; other `OP_*` flags and the `$options` argument are validated, then ignored |
| `imap_reopen` | switches folders on the same connection only: credentials aren't retained, so it can't reconnect elsewhere |
| `imap_search` | over POP3 only, the criteria grammar is a practical subset: `ALL`, the `SEEN`/`ANSWERED`/`DELETED`/`FLAGGED` pairs, substring `FROM`/`TO`/`SUBJECT`/`BODY`/`TEXT`, `SINCE`/`BEFORE`/`ON` |

### Connection string flags

In the `{host[:port][/flag...]}folder` mailbox spec, the flags that change behavior are:

- `/ssl` — implicit TLS
- `/tls` — STARTTLS
- `/novalidate-cert` — skip TLS certificate validation
- `/pop3` — connect over POP3 instead of IMAP
- `/readonly` — open the mailbox read-only, same as passing `OP_READONLY`

When no port is given, the default follows the service and encryption, like c-client: IMAP 143 (993 with `/ssl`), POP3 110 (995 with `/ssl`).

Any other flag (`/imap`, `/norsh`, `/secure`, `/debug`, …) is accepted and ignored, so existing connection strings parse fine.

### Cross-cutting limits

- **No NNTP**: `{host/nntp}` parses, then connects over IMAP anyway.
- **POP3** can do less than IMAP, inherited from the real extension rather than added here: one mailbox named `INBOX`, `SEARCH`/`STATUS`/`BODYSTRUCTURE` synthesized client-side, flags lasting only for the connection, copy/move/append/mailbox-management refused.
- **Warnings** are `E_USER_WARNING`, not `E_WARNING`: userland can't raise the level the C extension used.

### The 72 implemented functions

`imap_8bit`,
`imap_alerts`,
`imap_append`,
`imap_base64`,
`imap_binary`,
`imap_body`,
`imap_bodystruct`,
`imap_check`,
`imap_clearflag_full`,
`imap_close`,
`imap_create`,
`imap_createmailbox`,
`imap_delete`,
`imap_deletemailbox`,
`imap_errors`,
`imap_expunge`,
`imap_fetchbody`,
`imap_fetchheader`,
`imap_fetchmime`,
`imap_fetch_overview`,
`imap_fetchstructure`,
`imap_fetchtext` (alias of `imap_body`),
`imap_gc`,
`imap_getacl`,
`imap_getmailboxes`,
`imap_get_quota`,
`imap_get_quotaroot`,
`imap_getsubscribed`,
`imap_headerinfo`,
`imap_headers`,
`imap_is_open`,
`imap_last_error`,
`imap_list`,
`imap_listmailbox` (alias of `imap_list`),
`imap_listsubscribed` (alias of `imap_lsub`),
`imap_lsub`,
`imap_mail`,
`imap_mailboxmsginfo`,
`imap_mail_compose`,
`imap_mail_copy`,
`imap_mail_move`,
`imap_mime_header_decode`,
`imap_msgno`,
`imap_mutf7_to_utf8`,
`imap_num_msg`,
`imap_num_recent`,
`imap_open`,
`imap_ping`,
`imap_qprint`,
`imap_rename`,
`imap_renamemailbox`,
`imap_reopen`,
`imap_rfc822_parse_adrlist`,
`imap_rfc822_parse_headers`,
`imap_rfc822_write_address`,
`imap_savebody`,
`imap_search`,
`imap_setacl`,
`imap_setflag_full`,
`imap_set_quota`,
`imap_sort`,
`imap_status`,
`imap_subscribe`,
`imap_thread`,
`imap_timeout`,
`imap_uid`,
`imap_undelete`,
`imap_unsubscribe`,
`imap_utf7_decode`,
`imap_utf7_encode`,
`imap_utf8`,
`imap_utf8_to_mutf7`

## Development

```bash
make install          # composer install
make test-unit        # pure-PHP tests, no server needed
make test-integration  # spins up disposable Greenmail (IMAP+POP3) and Dovecot servers, runs the full suite against them
make test              # both of the above
```

Docker or Podman is required for `test-integration` (a `docker-compose.yml` is included for the equivalent setup with compose tooling). Almost every test runs against Greenmail; a second Dovecot fixture covers the two commands Greenmail has no support for, THREAD and ACL. Tests needing it skip themselves when it isn't running.

### Verifying against real ext-imap

`make parity` runs the exact same integration suite a second time, in a PHP 8.3 container with the genuine `ext-imap` extension installed from source, against the same Greenmail fixture. This is the real check that this polyfill's shapes and behavior actually match the extension it's replacing, not just internally-consistent test assertions.

```bash
make parity
```

This requires building a container image (`Dockerfile.parity`) the first time, which takes a few minutes.

## License

MIT
