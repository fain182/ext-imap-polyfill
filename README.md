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

## Tested against the real extension

Matching the manual isn't enough — the point is matching *the extension you're replacing*, quirks included: the property order inside a `stdClass`, the fact that `imap_fetch_overview()` returns `[]` where its neighbours return `false`, the exact `ValueError` text on a bad flag bitmask.

So the test suite doesn't just run against this package. **Every integration test runs a second time against the genuine `ext-imap`**, in a PHP 8.3 container, hitting the same servers — if the polyfill and the extension disagree, the build says so. On top of that, a unit suite covers the internals, and the whole thing is re-run against a second IMAP server (Greenmail and Dovecot) to catch behaviour that only holds on one of them.

That's what caught `imap_uid()` over POP3 returning the server's UIDL cast to an integer — right on one server, nonsense on the other.

POP3 is supported too, with the same reduced feature set it has under the real extension.

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

Anything not mentioned here behaves as the extension does; that is what the
parity suite is for. Below are the few places it doesn't, and the behaviours
worth knowing before you migrate even where it does.

| Function | Divergence |
|---|---|
| `imap_check`, `imap_mailboxmsginfo` | the `Mailbox` host reads back as you wrote it in the spec; the extension reports the name DNS resolved it to |
| `imap_open` with `/tls` | negotiates the best TLS version both ends support, so it connects where the extension's `/tls` fails outright — that one asks for TLS 1.0 and nothing else |
| `imap_timeout` | `IMAP_READTIMEOUT` and `IMAP_WRITETIMEOUT` are one value: setting either sets both, since a PHP socket has a single timeout for both directions |
| `imap_utf8` | returns precomposed UTF-8 (`café`, U+00E9) where the extension returns the decomposed form (`cafe` + U+0301); the two do not compare equal |

**`imap_open()` switches and flags.** `/secure`, `OP_SECURE` and `/authuser=`
refuse the connection outright: they ask for an authentication that keeps the
password off the wire, and this package only speaks `LOGIN`. An unrecognized
switch fails the open too — `{host/nowalidate-cert}` returns `false` and
"invalid remote specification" rather than connecting with the misspelling
ignored. Accepted and inert: `OP_DEBUG`, `/debug`, `OP_SHORTCACHE`, `/tryssl`,
`/loser`, and the `$options` argument.

**Connections upgrade themselves.** `STARTTLS` — `STLS` over POP3 — goes out
whenever the server offers it and the spec said neither `/ssl` nor `/notls`, so
a cleartext spec against a modern server ends up encrypted, and `imap_check()`
reports the `/tls` it negotiated.

**Search criteria.** `imap_search()` and `imap_sort()` accept `ALL`,
`ANSWERED`, `BCC`, `BEFORE`, `BODY`, `CC`, `DELETED`, `FLAGGED`, `FROM`,
`KEYWORD`, `NEW`, `OLD`, `ON`, `RECENT`, `SEEN`, `SINCE`, `SUBJECT`, `TEXT`,
`TO`, `UNANSWERED`, `UNDELETED`, `UNFLAGGED`, `UNKEYWORD` and `UNSEEN` —
narrower than IMAP's own `SEARCH` grammar, and the same set the extension
accepts. Anything else, `HEADER` and `OR` and `NOT` and `LARGER` and `SMALLER`
and `DRAFT` and the `SENT*` dates included, returns `false` with
`Unknown search criterion: …`. Dates have to fall between 1970 and 2097.

**What throws**, rather than returning `false`: `imap_scan()`,
`imap_scanmailbox()` and `imap_listscan()`, which speak a command no reachable
server answers; a `{host/nntp}` spec, since this package has no NNTP; and
`imap_mail()` on Windows with no `sendmail_path` set, since it has no SMTP
client either.

**Warnings** are raised as `E_USER_WARNING` rather than `E_WARNING`, which
userland cannot produce. The text is the same; an error handler filtering on
the level will see the difference.

</details>
