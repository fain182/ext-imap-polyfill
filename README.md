# ext-imap-polyfill

A drop-in polyfill for the `imap_*` functions removed from PHP core in 8.4.

PHP 8.4 moved `ext-imap` out of core and onto PECL ([RFC](https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)). The C library it wraps (c-client) has been unmaintained since 2007 and is disappearing from Linux distributions, so installing the PECL package is getting harder every release. Codebases built on the `imap_*` functions are usually rewritten against an OOP library like [webklex/php-imap](https://github.com/Webklex/php-imap) instead — a real migration effort, not a version bump.

This package lets you skip that rewrite for the common cases: it defines the same global `imap_*` functions, backed by webklex/php-imap for IMAP and a small raw client for POP3, and only activates if `ext-imap` isn't already loaded. **IMAP and POP3** — unlike the real extension, it doesn't speak NNTP.

## Install

```bash
composer require fain182/ext-imap-polyfill
```

No code changes. If `ext-imap` is present (e.g. you're still on PHP 8.3), the polyfill is a no-op — safe to add before you upgrade, not just after.

## Coverage

This is not a reimplementation of all `imap_*` functions — **65 of 75 (87%)** are implemented, chosen to cover the common path of connecting, reading, and moderating a mailbox. Calling any function marked ❌ below will simply hit PHP's "undefined function" error, same as before this package existed.

Every implemented function's object/array shape (property names, casing, flag semantics) is checked against the real extension — see [Verifying against real ext-imap](#verifying-against-real-ext-imap) below. Known, deliberate divergences are called out in the notes column; anything not noted is expected to match exactly.

| Function | Implemented | Notes |
|---|---|---|
| `imap_8bit` | ✅ | |
| `imap_alerts` | ✅ | never populated — this polyfill doesn't surface server `* OK [ALERT]` responses |
| `imap_append` | ✅ | |
| `imap_base64` | ✅ | |
| `imap_binary` | ✅ | wraps output at 60 chars/line like c-client's `rfc822_binary` |
| `imap_body` | ✅ |  |
| `imap_bodystruct` | ✅ | msgno-only, never uid — c-client's `mail_body()` has no UID equivalent, unlike `imap_fetchbody()` |
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
| `imap_fetchtext` | ✅ | alias of `imap_body` |
| `imap_gc` | ✅ | this polyfill keeps no cache, so once the flags bitmask is validated it's a no-op that always returns `true`, like the real extension |
| `imap_getacl` | ❌ | |
| `imap_getmailboxes` | ✅ | |
| `imap_get_quota` | ❌ | |
| `imap_get_quotaroot` | ❌ | |
| `imap_getsubscribed` | ✅ |  |
| `imap_headerinfo` | ✅ | `fetchfrom`/`fetchsubject` (nonzero `$from_length`/`$subject_length`) not implemented |
| `imap_headers` | ✅ | custom user-defined flags (the `{flag}` segment) are never populated, since this polyfill doesn't track them |
| `imap_is_open` | ✅ | |
| `imap_last_error` | ✅ | |
| `imap_list` | ✅ | |
| `imap_listmailbox` | ✅ | alias of `imap_list` |
| `imap_listscan` | ❌ | |
| `imap_listsubscribed` | ✅ | alias of `imap_lsub` |
| `imap_lsub` | ✅ |  |
| `imap_mail` | ❌ | |
| `imap_mailboxmsginfo` | ✅ | `Mailbox` property echoes the input spec rather than the c-client-normalized form |
| `imap_mail_compose` | ❌ | |
| `imap_mail_copy` | ✅ |  |
| `imap_mail_move` | ✅ | copies and marks the source `\Deleted` without expunging, like c-client; target is a bare folder name |
| `imap_mime_header_decode` | ✅ | |
| `imap_msgno` | ✅ | |
| `imap_mutf7_to_utf8` | ✅ | |
| `imap_num_msg` | ✅ | |
| `imap_num_recent` | ✅ | cached client-side read, like `imap_num_msg` |
| `imap_open` | ✅ | |
| `imap_ping` | ✅ |  |
| `imap_qprint` | ✅ | |
| `imap_rename` | ✅ | |
| `imap_renamemailbox` | ✅ | |
| `imap_reopen` | ✅ | only switches folders on the same connection — can't reconnect to a different host, since credentials aren't retained after `imap_open` |
| `imap_rfc822_parse_adrlist` | ✅ | |
| `imap_rfc822_parse_headers` | ✅ | |
| `imap_rfc822_write_address` | ✅ | |
| `imap_savebody` | ✅ | accepts a file path or an open stream resource; like the real extension, returns `true` regardless of whether the section fetch itself found anything, as long as the destination could be opened |
| `imap_scan` | ❌ | |
| `imap_scanmailbox` | ❌ | |
| `imap_search` | ✅ | |
| `imap_setacl` | ❌ | |
| `imap_setflag_full` | ✅ | |
| `imap_set_quota` | ❌ | |
| `imap_sort` | ✅ | `SORTSUBJECT` strips a leading `Re:`/`Fwd:` for comparison, not the full RFC5256 base-subject algorithm |
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

A few deviations from the real extension that a package evaluator should know about before relying on it:

- `{host/nntp}` is parsed but ignored (falls back to IMAP); `{host/pop3}` genuinely connects over POP3 — see the POP3 notes below.
- **POP3** (`{host/pop3}`): matches real ext-imap's own treatment of POP3 — a single mailbox always named `INBOX` (any other folder in the spec fails to open, and `OP_READONLY` fails to open at all, both like the real extension); `SEARCH`, `STATUS`, and `BODYSTRUCTURE` are all synthesized client-side, since POP3 has none of them on the wire; flags (`imap_setflag_full`/`imap_clearflag_full`/`\Seen` etc.) exist only for the lifetime of the connection, since POP3 has no persistent flag storage; `imap_mail_copy`/`imap_mail_move`/`imap_append`/mailbox-creation-or-rename all fail outright, same as real ext-imap. `imap_search()`'s criteria grammar over POP3 is a practical subset (`ALL`, `SEEN`/`UNSEEN`, `ANSWERED`/`UNANSWERED`, `DELETED`/`UNDELETED`, `FLAGGED`/`UNFLAGGED`, `FROM`/`TO`/`SUBJECT`/`BODY`/`TEXT` substring match, `SINCE`/`BEFORE`/`ON`), not the full RFC3501 grammar.
- Warnings are raised as `E_USER_WARNING`, not `E_WARNING` — userland code can't raise the exact error level the C extension uses.
- `OP_HALFOPEN` and most other `OP_*` open flags are accepted (to avoid spurious `ValueError`s) but have no effect; only `OP_READONLY` and `CL_EXPUNGE` actually change behavior.
- `imap_reopen()` only switches folders on the already-open connection — it can't reconnect to a different host, since `imap_open()`'s credentials aren't retained.
- `imap_alerts()` is never populated; this polyfill doesn't surface server `* OK [ALERT]` responses.
- `imap_open()`'s `$options` argument (e.g. `DISABLE_AUTHENTICATOR`) is ignored.

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
