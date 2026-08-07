# ext-imap-polyfill

[![Tests](https://github.com/fain182/ext-imap-polyfill/actions/workflows/tests.yml/badge.svg)](https://github.com/fain182/ext-imap-polyfill/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/fain182/ext-imap-polyfill)](https://packagist.org/packages/fain182/ext-imap-polyfill)
[![Downloads](https://img.shields.io/packagist/dt/fain182/ext-imap-polyfill)](https://packagist.org/packages/fain182/ext-imap-polyfill)
[![PHP Version](https://img.shields.io/packagist/dependency-v/fain182/ext-imap-polyfill/php?label=php)](https://packagist.org/packages/fain182/ext-imap-polyfill)
[![License](https://img.shields.io/packagist/l/fain182/ext-imap-polyfill)](LICENSE)

**A drop-in polyfill for the `imap_*` functions removed from PHP core in 8.4.** Install it and your existing code keeps working — same function names, same arguments, same objects coming back.

PHP 8.4 moved `ext-imap` onto PECL ([RFC](https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)), where it still needs c-client — unmaintained since 2007 and disappearing from distributions. This package defines the same functions in pure PHP instead, on top of [directorytree/imapengine](https://github.com/DirectoryTree/ImapEngine).

## Install

```bash
composer require fain182/ext-imap-polyfill
```

That's the whole migration. No call sites to touch, no API to learn, nothing else to do.

If `ext-imap` is present — you're still on PHP 8.3, or someone installed the PECL build — the polyfill is a no-op and the real extension keeps handling every call. It's safe to add *before* you upgrade, not just after. It also declares `provide: ext-imap`, so dependencies that require the extension install cleanly alongside it.

## Why a polyfill instead of rewriting

Libraries like [webklex/php-imap](https://github.com/Webklex/php-imap) or [ddeboer/imap](https://github.com/ddeboer/imap) are good, and if you're writing new code you should probably use one. But they have their own API: adopting one means touching every call site, re-deriving what your code did with `imap_fetchstructure()`'s parts tree, and re-testing all of it — a project, scheduled against everything else.

This package is a `composer require` in an afternoon. Your diff is one line in `composer.json`.

## Faithful, and checked that way

Matching the manual isn't enough — the point is matching *the extension you're replacing*, quirks included: the property order inside a `stdClass`, the fact that `imap_fetch_overview()` returns `[]` where its neighbours return `false`, the exact `ValueError` text on a bad flag bitmask.

So the test suite doesn't just run against this package. **The same 279 integration tests run a second time against the genuine `ext-imap`**, in a PHP 8.3 container, hitting the same servers — if the polyfill and the extension disagree, the build says so. On top of that, 96 unit tests cover the internals, and the whole suite is re-run against a second IMAP server (Greenmail and Dovecot) to catch behaviour that only holds on one of them.

That's what caught `imap_uid()` over POP3 returning the server's UIDL cast to an integer — right on one server, nonsense on the other.

POP3 is supported too, with the same reduced feature set it has under the real extension.

<details>
<summary>Fine print: the corners where it differs</summary>

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

Two things throw instead of pretending, rather than diverging quietly. `imap_scan()`, `imap_scanmailbox()` and `imap_listscan()` speak a command dropped from IMAP4rev1 that in practice only c-client's own UW-IMAP server ever implemented — no server you can reach would answer them. And opening a `{host/nntp}` mailbox throws: the real extension speaks NNTP, this doesn't.

`imap_open()` acts on `OP_READONLY` and `CL_EXPUNGE` and on the `/ssl`, `/tls`, `/novalidate-cert`, `/pop3` and `/readonly` flags; the remaining `OP_*` flags, the `$options` argument and flags like `/debug` and `/secure` are parsed and then ignored.

</details>

<details>
<summary>Every function it defines</summary>

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

</details>

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT
