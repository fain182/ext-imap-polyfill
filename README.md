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

This is not a reimplementation of all ~80 `imap_*` functions — only the 23 listed below are implemented, chosen to cover the common path of connecting, reading, and moderating a mailbox. Calling any other `imap_*` function will simply hit PHP's "undefined function" error, same as before this package existed.

| Function | Notes |
|---|---|
| `imap_open` | `OP_READONLY` unsupported; retries argument ignored |
| `imap_close` | supports `CL_EXPUNGE` |
| `imap_timeout` | process-global, like the original |
| `imap_last_error` | |
| `imap_errors` | |
| `imap_alerts` | never populated — this polyfill doesn't surface server `* OK [ALERT]` responses |
| `imap_num_msg` | |
| `imap_check` | `Mailbox` property echoes the input spec rather than the c-client-normalized form |
| `imap_search` | criteria string is split on whitespace, not parsed like c-client's grammar |
| `imap_fetchheader` | |
| `imap_headerinfo` | `fetchfrom`/`fetchsubject` (nonzero `$from_length`/`$subject_length`) not implemented |
| `imap_fetch_overview` | |
| `imap_fetchstructure` | |
| `imap_fetchbody` | |
| `imap_msgno` | |
| `imap_uid` | |
| `imap_list` | |
| `imap_getmailboxes` | |
| `imap_setflag_full` | |
| `imap_delete` | |
| `imap_expunge` | |
| `imap_append` | |
| `imap_utf8` | |
| `imap_rfc822_parse_adrlist` | |

Every function's object/array shape (property names, casing, flag semantics) is checked against the real extension — see [Verifying against real ext-imap](#verifying-against-real-ext-imap) below. Known, deliberate divergences are called out in the notes above; anything not listed there is expected to match exactly.

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
