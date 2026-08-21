# ext-imap-polyfill

[![Tests](https://github.com/fain182/ext-imap-polyfill/actions/workflows/tests.yml/badge.svg)](https://github.com/fain182/ext-imap-polyfill/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/fain182/ext-imap-polyfill)](https://packagist.org/packages/fain182/ext-imap-polyfill)
[![Downloads](https://img.shields.io/packagist/dt/fain182/ext-imap-polyfill)](https://packagist.org/packages/fain182/ext-imap-polyfill)
[![PHP Version](https://img.shields.io/packagist/dependency-v/fain182/ext-imap-polyfill/php?label=php)](https://packagist.org/packages/fain182/ext-imap-polyfill)
[![License](https://img.shields.io/packagist/l/fain182/ext-imap-polyfill)](LICENSE)

**A drop-in polyfill for the `imap_*` functions removed from PHP core in 8.4.** Install it and your existing code keeps working — same function names, same arguments, same objects coming back.

PHP 8.4 moved `ext-imap` onto PECL ([RFC](https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)), where it still needs c-client — last released in 2011 and disappearing from distributions. This package defines the same functions in pure PHP instead, on top of [directorytree/imapengine](https://github.com/DirectoryTree/ImapEngine).

## Install

```bash
composer require fain182/ext-imap-polyfill
```

That's the whole migration: no call sites to touch, nothing else to do.

If `ext-imap` is present — you're still on PHP 8.3, or someone installed the PECL build — the polyfill is a no-op and the real extension keeps handling every call. It's safe to add *before* you upgrade, not just after. It also declares `provide: ext-imap`, so dependencies that require the extension install cleanly alongside it.

## Tested against the real extension

Matching the manual isn't enough — the point is matching *the extension you're replacing*, quirks included: the property order inside a `stdClass`, the fact that `imap_fetch_overview()` returns `[]` where its neighbours return `false`, the exact `ValueError` text on a bad flag bitmask.

So the test suite doesn't just run against this package. **Every integration test runs a second time against the genuine `ext-imap`**, in a PHP 8.3 container, hitting the same servers — if the polyfill and the extension disagree, the build says so. On top of that, a unit suite covers the internals, and the whole thing is re-run against a second IMAP server (Greenmail and Dovecot) to catch behaviour that only holds on one of them.

That's what caught `imap_uid()` over POP3 returning the server's UIDL cast to an integer — right on one server, nonsense on the other.

POP3 is supported too, and runs through the same parity checks.

<details>
<summary>Function reference</summary>

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

### Notes on individual functions

Your code has already run against the extension, so the only thing worth
reading here is where this package answers differently. Everything else
behaves as it did — that is what the parity suite is for.

| Function | Divergence |
|---|---|
| `imap_check`, `imap_mailboxmsginfo` | the `Mailbox` host reads back as you wrote it in the spec; the extension reports the name DNS resolved it to |
| `imap_open` with `/tls` | negotiates the best TLS version both ends support, so it connects where the extension's `/tls` fails outright — that one asks for TLS 1.0 and nothing else |
| `imap_open` with `/secure`, `OP_SECURE`, `/authuser=` | always refused: they ask for an authentication that keeps the password off the wire, and this package only speaks `LOGIN`. The extension refuses too unless the server offers a SASL mechanism it can use |
| `imap_timeout` | `IMAP_READTIMEOUT` and `IMAP_WRITETIMEOUT` are one value: setting either sets both, since a PHP socket has a single timeout for both directions |
| `imap_utf8` | returns precomposed UTF-8 (`café`, U+00E9) where the extension returns the decomposed form (`cafe` + U+0301); the two do not compare equal |

**Refused rather than sent** is the one thing this package declines to put
on the wire where the extension sends it: an argument that travels unquoted
— a flag, a message sequence, a body section, a POP3 user name or password —
holding a CR or LF, which would end the command and start a second one in a
session already logged in. The function answers the way it answers any other
failure, with `Command argument contains a line break` on the error stack.

**What throws**, rather than returning `false`: `imap_scan()`,
`imap_scanmailbox()` and `imap_listscan()`, which speak a command no reachable
server answers; a `{host/nntp}` spec, since this package has no NNTP; and
`imap_mail()` on Windows with no `sendmail_path` set, since it has no SMTP
client either.

**Warnings** are raised as `E_USER_WARNING` rather than `E_WARNING`, which
userland cannot produce. The text is the same; an error handler filtering on
the level will see the difference.

**Errors nobody read** stay on the stack. The extension prints whatever
`imap_errors()` never drained at the end of the request, as notices reading
`PHP Request Shutdown: ... (errflg=N)`; a notice raised from userland cannot
carry the location that one does, so this package says nothing instead.

**Passing `null`** where a string parameter is declared is a `TypeError` here
and a deprecation notice in the extension, which then reads it as `""`:
internal functions are allowed a coercion userland ones are not. For the same
reason, a `TypeError` from this package names the call site (`, called in
...`) where the extension's does not.

</details>
