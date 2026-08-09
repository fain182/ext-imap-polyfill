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

| Function | Divergence |
|---|---|
| `imap_check`, `imap_mailboxmsginfo` | the `Mailbox` host stays as written in the spec; c-client reports the resolver's canonical name for it. PHP exposes no way to ask for that name — `gethostbyname()` returns only an address, and the reverse lookup answers a different question, and often a different name |
| `imap_mail` | always delivers through the `sendmail_path` pipe, and returns false when that ini is empty |
| `imap_open` with `/tls` | negotiates the best TLS version both ends support. c-client asks for TLS 1.0 and nothing else, so against a server on the usual TLS 1.2 minimum its `/tls` fails outright and needs `/tls-sslv23` to work at all — the same is true of the upgrade it performs unasked |
| `imap_timeout` | `IMAP_WRITETIMEOUT` is stored and read back, but not applied: a PHP socket has one timeout covering both directions, and the read timeout takes it |
| `imap_utf8` | decodes an ISO-8859-1 segment to precomposed UTF-8 (`café`, U+00E9); c-client emits the decomposed form (`cafe` + U+0301) |

`imap_open()` reads the whole `{host}` switch set and every `OP_*` flag, including the ones whose faithful answer is a refusal: `/secure`, `OP_SECURE` and `/authuser=` ask for an authentication stronger than `LOGIN`, which this package has no SASL to provide, so they get c-client's own "Can't do secure authentication with this server" rather than a plaintext login. The set is closed, as it is in c-client: `{host/nowalidate-cert}` is not a connection with a switch ignored, it is `false` and "invalid remote specification".

Some do nothing here: `OP_DEBUG` and `/debug`, whose telemetry the real extension drops on the floor too (`mm_dlog()` is an empty function), `OP_SHORTCACHE`, which tunes a c-client message cache that has no counterpart here, and `/tryssl` and `/loser`, which only change how c-client goes about connecting. The `$options` argument is still parsed and ignored.

Connections upgrade themselves: `STARTTLS` (or POP3's `STLS`) goes out whenever the server advertises it and the spec didn't say `/ssl` or `/notls`, which is what c-client does, so a cleartext spec against a modern server ends up encrypted — and says so in the `Mailbox` string `imap_check()` reports.

`imap_search()` and `imap_sort()` accept the criteria c-client can build a `SEARCHPGM` from, which is narrower than IMAP's own `SEARCH` grammar: `ALL`, `ANSWERED`, `BCC`, `BEFORE`, `BODY`, `CC`, `DELETED`, `FLAGGED`, `FROM`, `KEYWORD`, `NEW`, `OLD`, `ON`, `RECENT`, `SEEN`, `SINCE`, `SUBJECT`, `TEXT`, `TO`, `UNANSWERED`, `UNDELETED`, `UNFLAGGED`, `UNKEYWORD`, `UNSEEN`. There is no `HEADER`, `OR`, `NOT`, `LARGER`, `SMALLER`, `DRAFT` or `SENT*`, and naming one returns `false` with `Unknown search criterion: …` — the same answer the real extension gives, on IMAP as much as on POP3. Dates must be ones the program can hold, between 1970 and 2097.

`imap_scan()`, `imap_scanmailbox()` and `imap_listscan()` throw: they speak a command dropped from IMAP4rev1 that in practice only c-client's own UW-IMAP server ever implemented, so no server you can reach would answer them. Opening a `{host/nntp}` mailbox throws too — the real extension speaks NNTP, this doesn't.

Warnings are raised as `E_USER_WARNING` rather than `E_WARNING`, which userland cannot produce. The message text is the same; an error handler that filters on the level will see the difference.

</details>
