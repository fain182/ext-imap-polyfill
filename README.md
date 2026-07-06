# ext-imap-polyfill

A drop-in polyfill for the `imap_*` functions removed from PHP core in 8.4.

PHP 8.4 moved `ext-imap` out of core and onto PECL ([RFC](https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)). The C library it wraps (c-client) has been unmaintained since 2007 and is disappearing from Linux distributions, so installing the PECL package is getting harder every release. Codebases built on the `imap_*` functions are usually rewritten against an OOP library like [webklex/php-imap](https://github.com/Webklex/php-imap) instead — a real migration effort, not a version bump.

This package lets you skip that rewrite for the common cases: it defines the same global `imap_*` functions, backed by webklex/php-imap, and only activates if `ext-imap` isn't already loaded.

## Install

```bash
composer require fain182/ext-imap-polyfill
```

No code changes. If `ext-imap` is present (e.g. you're still on PHP 8.3), the polyfill is a no-op — safe to add before you upgrade, not just after.

## Coverage

This is not a reimplementation of all `imap_*` functions — **47 of 75 (63%)** are implemented, chosen to cover the common path of connecting, reading, and moderating a mailbox. Calling any function marked ❌ below will simply hit PHP's "undefined function" error, same as before this package existed.

Every implemented function's object/array shape (property names, casing, flag semantics) is checked against the real extension — see [Verifying against real ext-imap](#verifying-against-real-ext-imap) below. Known, deliberate divergences are called out in the notes column; anything not noted is expected to match exactly.

| Function | Implemented | Notes |
|---|---|---|
| `imap_8bit` | ✅ | |
| `imap_alerts` | ✅ | never populated — this polyfill doesn't surface server `* OK [ALERT]` responses |
| `imap_append` | ✅ | |
| `imap_base64` | ✅ | |
| `imap_binary` | ✅ | wraps output at 60 chars/line like c-client's `rfc822_binary` |
| `imap_body` | ❌ | |
| `imap_bodystruct` | ❌ | |
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
| `imap_fetchmime` | ❌ | |
| `imap_fetch_overview` | ✅ | |
| `imap_fetchstructure` | ✅ | |
| `imap_fetchtext` | ❌ | |
| `imap_gc` | ❌ | |
| `imap_getacl` | ❌ | |
| `imap_getmailboxes` | ✅ | |
| `imap_get_quota` | ❌ | |
| `imap_get_quotaroot` | ❌ | |
| `imap_getsubscribed` | ❌ | |
| `imap_headerinfo` | ✅ | `fetchfrom`/`fetchsubject` (nonzero `$from_length`/`$subject_length`) not implemented |
| `imap_headers` | ❌ | |
| `imap_is_open` | ✅ | |
| `imap_last_error` | ✅ | |
| `imap_list` | ✅ | |
| `imap_listmailbox` | ❌ | |
| `imap_listscan` | ❌ | |
| `imap_listsubscribed` | ❌ | |
| `imap_lsub` | ❌ | |
| `imap_mail` | ❌ | |
| `imap_mailboxmsginfo` | ❌ | |
| `imap_mail_compose` | ❌ | |
| `imap_mail_copy` | ❌ | |
| `imap_mail_move` | ❌ | |
| `imap_mime_header_decode` | ✅ | |
| `imap_msgno` | ✅ | |
| `imap_mutf7_to_utf8` | ✅ | |
| `imap_num_msg` | ✅ | |
| `imap_num_recent` | ✅ | cached client-side read, like `imap_num_msg` |
| `imap_open` | ✅ | |
| `imap_ping` | ❌ | |
| `imap_qprint` | ✅ | |
| `imap_rename` | ✅ | |
| `imap_renamemailbox` | ✅ | |
| `imap_reopen` | ✅ | only switches folders on the same connection — can't reconnect to a different host, since credentials aren't retained after `imap_open` |
| `imap_rfc822_parse_adrlist` | ✅ | |
| `imap_rfc822_parse_headers` | ✅ | |
| `imap_rfc822_write_address` | ✅ | |
| `imap_savebody` | ❌ | |
| `imap_scan` | ❌ | |
| `imap_scanmailbox` | ❌ | |
| `imap_search` | ✅ | |
| `imap_setacl` | ❌ | |
| `imap_setflag_full` | ✅ | |
| `imap_set_quota` | ❌ | |
| `imap_sort` | ❌ | |
| `imap_status` | ❌ | |
| `imap_subscribe` | ✅ | |
| `imap_thread` | ❌ | |
| `imap_timeout` | ✅ | |
| `imap_uid` | ✅ | |
| `imap_undelete` | ✅ | |
| `imap_unsubscribe` | ✅ | |
| `imap_utf7_decode` | ✅ | |
| `imap_utf7_encode` | ✅ | |
| `imap_utf8` | ✅ | |
| `imap_utf8_to_mutf7` | ✅ | |

## Development

```bash
make install          # composer install
make test-unit        # pure-PHP tests, no server needed
make test-integration  # spins up a disposable Greenmail IMAP server, runs the full suite against it
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
