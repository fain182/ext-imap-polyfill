# ext-imap-polyfill

[![Tests](https://github.com/fain182/ext-imap-polyfill/actions/workflows/tests.yml/badge.svg)](https://github.com/fain182/ext-imap-polyfill/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/fain182/ext-imap-polyfill)](https://packagist.org/packages/fain182/ext-imap-polyfill)
[![PHP Version](https://img.shields.io/packagist/dependency-v/fain182/ext-imap-polyfill/php?label=php)](https://packagist.org/packages/fain182/ext-imap-polyfill)
[![License](https://img.shields.io/packagist/l/fain182/ext-imap-polyfill)](LICENSE)

A drop-in polyfill for the `imap_*` functions removed from PHP core in 8.4.

PHP 8.4 moved `ext-imap` out of core and onto PECL ([RFC](https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)). The C library it wraps (c-client) has been unmaintained since 2007 and is disappearing from Linux distributions, so installing the PECL package is getting harder every release. Codebases built on the `imap_*` functions are usually rewritten against an OOP library like [webklex/php-imap](https://github.com/Webklex/php-imap) instead — a real migration effort, not a version bump.

This package lets you skip that rewrite for the common cases: it defines the same global `imap_*` functions, backed by webklex/php-imap for IMAP and a small raw client for POP3, and only activates if `ext-imap` isn't already loaded. **IMAP and POP3** — unlike the real extension, it doesn't speak NNTP.

## Install

```bash
composer require fain182/ext-imap-polyfill
```

No code changes. If `ext-imap` is present (e.g. you're still on PHP 8.3), the polyfill is a no-op — safe to add before you upgrade, not just after.

Requires PHP 8.1+. The webklex/php-imap dependency declares a handful of extension requirements (`ext-mbstring`, `ext-iconv`, `ext-openssl`, `ext-libxml`, `ext-json`, `ext-fileinfo`, `ext-zip`); all but `ext-zip` are enabled in virtually every PHP build. `ext-zip` is only used by webklex code paths this polyfill never calls, so if you can't (or don't want to) install it:

```bash
composer require fain182/ext-imap-polyfill --ignore-platform-req=ext-zip
```

The package declares `provide: ext-imap`, so other dependencies that require `ext-imap` install cleanly alongside it.

## Usage

Your existing `imap_*` code runs unchanged:

```php
$imap = imap_open('{imap.example.com:993/imap/ssl}INBOX', 'user@example.com', $password);

foreach (imap_search($imap, 'UNSEEN') ?: [] as $msgno) {
    $overview = imap_fetch_overview($imap, (string) $msgno)[0];
    echo "{$overview->from}: {$overview->subject}\n";
}

imap_close($imap);
```

### Connection string flags

In the `{host[:port][/flag...]}folder` mailbox spec, the flags that change behavior are:

- `/ssl` — implicit TLS
- `/tls` — STARTTLS
- `/novalidate-cert` — skip TLS certificate validation
- `/pop3` — connect over POP3 instead of IMAP
- `/readonly` — open the mailbox read-only, same as passing `OP_READONLY`

When no port is given, the default follows the service and encryption, like c-client: IMAP 143 (993 with `/ssl`), POP3 110 (995 with `/ssl`).

Any other flag (`/imap`, `/norsh`, `/secure`, `/debug`, …) is accepted and ignored, so existing connection strings parse fine.

## Coverage

This is not a reimplementation of all `imap_*` functions — **70 of 75 (93%)** are implemented, chosen to cover the common path of connecting, reading, and moderating a mailbox. The missing five are ACL management (`imap_getacl`, `imap_setacl`) and scanning mailboxes by text content (`imap_scan`, `imap_scanmailbox`, `imap_listscan`). Calling any of them will simply hit PHP's "undefined function" error, same as before this package existed.

Every implemented function's object/array shape (property names, casing, flag semantics) is checked against the real extension — see [Verifying against real ext-imap](#verifying-against-real-ext-imap) below. Known, deliberate divergences are called out in the last column; an empty cell means the function is expected to match exactly.

| Function | Implemented | Divergences |
|---|---|---|
| `imap_8bit` | ✅ | |
| `imap_alerts` | ✅ | never populated — this polyfill doesn't surface server `* OK [ALERT]` responses |
| `imap_append` | ✅ | |
| `imap_base64` | ✅ | |
| `imap_binary` | ✅ | |
| `imap_body` | ✅ |  |
| `imap_bodystruct` | ✅ | |
| `imap_check` | ✅ | `Mailbox` property echoes the input spec rather than the c-client-normalized form |
| `imap_clearflag_full` | ✅ | |
| `imap_close` | ✅ | |
| `imap_create` | ✅ | |
| `imap_createmailbox` | ✅ | |
| `imap_delete` | ✅ | |
| `imap_deletemailbox` | ✅ | |
| `imap_errors` | ✅ | |
| `imap_expunge` | ✅ | |
| `imap_fetchbody` | ✅ | |
| `imap_fetchheader` | ✅ | |
| `imap_fetchmime` | ✅ | |
| `imap_fetch_overview` | ✅ | |
| `imap_fetchstructure` | ✅ | |
| `imap_fetchtext` (alias of `imap_body`) | ✅ | |
| `imap_gc` | ✅ | |
| `imap_getacl` | ❌ | |
| `imap_getmailboxes` | ✅ | |
| `imap_get_quota` | ✅ | |
| `imap_get_quotaroot` | ✅ | |
| `imap_getsubscribed` | ✅ |  |
| `imap_headerinfo` | ✅ | `fetchfrom`/`fetchsubject` (nonzero `$from_length`/`$subject_length`) not implemented |
| `imap_headers` | ✅ | custom user-defined flags (the `{flag}` segment) are never populated, since this polyfill doesn't track them |
| `imap_is_open` | ✅ | |
| `imap_last_error` | ✅ | |
| `imap_list` | ✅ | |
| `imap_listmailbox` (alias of `imap_list`) | ✅ | |
| `imap_listscan` | ❌ | |
| `imap_listsubscribed` (alias of `imap_lsub`) | ✅ | |
| `imap_lsub` | ✅ |  |
| `imap_mail` | ✅ | delivery always goes through the `sendmail_path` pipe (false when that ini is empty) — the real extension's Windows build spoke SMTP via the `SMTP`/`smtp_port` ini settings instead |
| `imap_mailboxmsginfo` | ✅ | `Mailbox` property echoes the input spec rather than the c-client-normalized form |
| `imap_mail_compose` | ✅ | address lists go through the same simplified parser as `imap_rfc822_parse_adrlist` (no group or route syntax); `8BIT` bodies are re-encoded with `quoted_printable_encode()`, whose soft-line-break positions can differ from c-client's `rfc822_8bit` |
| `imap_mail_copy` | ✅ |  |
| `imap_mail_move` | ✅ | |
| `imap_mime_header_decode` | ✅ | |
| `imap_msgno` | ✅ | |
| `imap_mutf7_to_utf8` | ✅ | |
| `imap_num_msg` | ✅ | |
| `imap_num_recent` | ✅ | |
| `imap_open` | ✅ | of the `$flags` bitmask, only `OP_READONLY` and `CL_EXPUNGE` change behavior — the other `OP_*` flags are validated, then ignored; the `$options` argument (e.g. `DISABLE_AUTHENTICATOR`) is ignored |
| `imap_ping` | ✅ |  |
| `imap_qprint` | ✅ | |
| `imap_rename` | ✅ | |
| `imap_renamemailbox` | ✅ | |
| `imap_reopen` | ✅ | only switches folders on the same connection — can't reconnect to a different host, since credentials aren't retained after `imap_open` |
| `imap_rfc822_parse_adrlist` | ✅ | |
| `imap_rfc822_parse_headers` | ✅ | |
| `imap_rfc822_write_address` | ✅ | |
| `imap_savebody` | ✅ | |
| `imap_scan` | ❌ | |
| `imap_scanmailbox` | ❌ | |
| `imap_search` | ✅ | |
| `imap_setacl` | ❌ | |
| `imap_setflag_full` | ✅ | |
| `imap_set_quota` | ✅ | |
| `imap_sort` | ✅ | always sorts client-side (a port of c-client's algorithms, including RFC 5256 base subjects for `SORTSUBJECT`); real ext-imap hands sorting to the server when it advertises the `SORT` capability, so results can differ on servers whose `SORT` deviates from RFC 5256 |
| `imap_status` | ✅ |  |
| `imap_subscribe` | ✅ | |
| `imap_thread` | ✅ | duplicate Message-IDs aren't given synthetic unique IDs |
| `imap_timeout` | ✅ | |
| `imap_uid` | ✅ | |
| `imap_undelete` | ✅ | |
| `imap_unsubscribe` | ✅ | |
| `imap_utf7_decode` | ✅ | |
| `imap_utf7_encode` | ✅ | |
| `imap_utf8` | ✅ | |
| `imap_utf8_to_mutf7` | ✅ | |

## Limitations

Cross-cutting divergences from the real extension (per-function ones are in the coverage table's Divergences column):

- **No NNTP**: `{host/nntp}` is parsed but ignored — the connection silently falls back to IMAP instead of talking NNTP.
- **POP3** support mirrors real ext-imap's own treatment of POP3: a single mailbox always named `INBOX`, `SEARCH`/`STATUS`/`BODYSTRUCTURE` synthesized client-side, flags lasting only for the lifetime of the connection, and copy/move/append/mailbox-management failing outright. The one divergence: `imap_search()`'s criteria grammar over POP3 is a practical subset (`ALL`, the `SEEN`/`ANSWERED`/`DELETED`/`FLAGGED` pairs, `FROM`/`TO`/`SUBJECT`/`BODY`/`TEXT` substring match, `SINCE`/`BEFORE`/`ON`), not the full RFC3501 grammar.
- **Warnings** are raised as `E_USER_WARNING`, not `E_WARNING` — userland code can't raise the exact error level the C extension uses.

## Development

```bash
make install          # composer install
make test-unit        # pure-PHP tests, no server needed
make test-integration  # spins up a disposable Greenmail IMAP+POP3 server, runs the full suite against it
make test              # both of the above
```

Docker or Podman is required for `test-integration` (a `docker-compose.yml` is included for the equivalent setup with compose tooling).

### Verifying against real ext-imap

`make parity` runs the exact same integration suite a second time, in a PHP 8.3 container with the genuine `ext-imap` extension installed from source, against the same Greenmail fixture. This is the real check that this polyfill's shapes and behavior actually match the extension it's replacing, not just internally-consistent test assertions.

```bash
make parity
```

This requires building a container image (`Dockerfile.parity`) the first time, which takes a few minutes.

## License

MIT
