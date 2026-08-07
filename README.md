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

## Compatibility

**72 of 75** `imap_*` functions are implemented, and what they return is checked against the real extension rather than against this package's own idea of it — the suite runs a second time with the genuine `ext-imap` loaded ([how](CONTRIBUTING.md)). Anything not named below matches it. POP3 works too, with the same reduced feature set it has there.

Two things are missing, and both refuse loudly rather than pretending. `imap_scan()`, `imap_scanmailbox()` and `imap_listscan()` throw: SCAN was dropped from IMAP4rev1 and in practice only c-client's own UW-IMAP server ever implemented it, so no server you can reach would answer them anyway. Opening a `{host/nntp}` mailbox throws too — the real extension speaks NNTP and this doesn't.

`imap_open()` acts on `OP_READONLY` and `CL_EXPUNGE` and on the `/ssl`, `/tls`, `/novalidate-cert`, `/pop3` and `/readonly` flags; the remaining `OP_*` flags, the `$options` argument and flags like `/debug` and `/secure` are parsed and then ignored.

<details>
<summary>Behavioural fine print</summary>

Worth reading if something doesn't match byte for byte, not before.

| Function | Divergence |
|---|---|
| `imap_check`, `imap_mailboxmsginfo` | the `Mailbox` host stays as written in the spec; c-client resolves it to its canonical DNS name |
| `imap_mail` | always delivers through the `sendmail_path` pipe, and returns false when that ini is empty |
| `imap_mail_compose` | a group address keeps its members (`Group: , a@b, c@d, ;`); c-client writes the group name and terminator with the member slots blank |
| `imap_search` | over POP3 only, the criteria grammar is a practical subset: `ALL`, the `SEEN`/`ANSWERED`/`DELETED`/`FLAGGED` pairs, substring `FROM`/`TO`/`SUBJECT`/`BODY`/`TEXT`, `SINCE`/`BEFORE`/`ON` |
| `imap_timeout` | `IMAP_WRITETIMEOUT` is stored and read back, but not applied: a PHP socket has one timeout covering both directions, and the read timeout takes it |
| `imap_utf7_encode`, `imap_utf7_decode` | non-ASCII is converted per character; c-client packs the input's bytes into UTF-16 units instead, so `caffè` encodes to `caff&AMMAqA-` rather than `caff&w6g-` |
| `imap_utf8` | decodes an ISO-8859-1 segment to precomposed UTF-8 (`café`, U+00E9); c-client emits the decomposed form (`cafe` + U+0301) |

Warnings are raised as `E_USER_WARNING` rather than `E_WARNING`, which userland cannot produce.

</details>

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

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT
