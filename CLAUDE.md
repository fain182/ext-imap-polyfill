# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A drop-in polyfill for PHP's `imap_*` functions (removed from core in 8.4), backed by webklex/php-imap. `bootstrap.php` is a no-op when the real `ext-imap` is loaded; otherwise it defines the same global constants and functions. Fidelity to the real extension — down to error-path return values, `ValueError` messages, and stdClass property names/casing — is the whole point of the project.

## Commands

```bash
make install           # composer install --ignore-platform-reqs (webklex hard-requires ext-zip for code paths this polyfill never calls)
make test-unit         # pure-PHP tests, no server
make test-integration  # spins up disposable Greenmail (podman/docker), runs suite, tears down
make test              # both
make parity            # same integration suite against REAL ext-imap (PHP 8.3 container) + same Greenmail
```

Single test: start the fixture with `make greenmail-up`, then
`vendor/bin/phpunit --filter test_name tests/Integration/ImapOpenTest.php`
(Greenmail listens on 127.0.0.1:13143; override with `IMAP_POLYFILL_TEST_HOST`/`IMAP_POLYFILL_TEST_PORT`). `make greenmail-down` when done.

## Architecture

Strict layering; each layer only talks to the next:

- **`src/functions.php`** — conditional (`function_exists`) one-liner shims. No logic beyond delegation, plus the user-facing `trigger_error` warning where ext-imap emits one (e.g. `imap_open`). Aliases (`imap_fetchtext`, `imap_listmailbox`, `imap_delete`…) call the canonical function.
- **`src/Session/`** — the imap_* contract layer, instantiated per call around an `\IMAP\Connection`:
  - `Session` — connection lifecycle: `open()` (static factory, the body of `imap_open`), close, reopen, ping, check, cached counters.
  - `Mailbox` — operations on messages in the currently selected folder (search, fetch*, flags, copy/move, append).
  - `MailboxHierarchy` — folder-level operations, nothing selected (LIST/LSUB, STATUS, create/delete/rename/subscribe).
- **`src/Connection/Connection.php`** (`namespace IMAP`) — polyfill of the opaque native `IMAP\Connection` class. Sole owner of the webklex `Client`; exposes named wire operations. Knows nothing about imap_* contracts, ErrorStack, or return-value conventions. Also holds cached message counts (mirrors c-client's `stream->nmsgs`: `imap_num_msg` is a cached read that survives a dead connection).
- **`src/Connection/Protocol.php`** — Gateway to webklex's raw `ImapProtocol` for operations the high-level client API lacks (UID↔msgno, raw FETCH/SEARCH/STORE, LSUB, STATUS); unwraps the `validatedData()` envelope.
- **Value objects**: `MailboxSpec` (parses `{host:port/flags}folder` for open/reopen; empty folder defaults to INBOX; throws `ValueError` on malformed input) vs `MailboxReference` (reference/folder arguments of list/append/status: `bareReference` + `displayPrefix`). Don't conflate them.
- **`src/Message/*`** — builders producing the exact stdClass shapes of the real extension (property names, casing, conditional presence).
- **`src/Support/ErrorStack.php`** — process-global static state, deliberately: the real extension has one global error stack (`imap_errors()` takes no connection). `imap_errors()` drains it *and* clears the last error, because in `php_imap.c` both functions read the same stack.

## Error-handling contract (do not "fix" it)

Wrappers replicate ext-imap, not modern taste: catch `\Throwable`, push the message to `ErrorStack`, and return whatever the real function returns on failure — which varies deliberately (`false` for most fetches, `[]` for `imap_fetch_overview`, `true` always for `imap_setflag_full`/`imap_expunge`/`imap_delete`, `0` for `imap_msgno`). Invalid flag bitmasks throw `ValueError` with messages copied from `php_imap.c`'s `zend_argument_value_error` calls. Any call on a closed connection throws `ValueError` via `Connection::ensureOpen()` (`imap_is_open` is the one exception). Divergences from the real extension are documented in comments at the point of divergence and in the README's Coverage divergences table.

## Testing philosophy

Integration tests are **characterization tests of the real extension** and must be parity-safe: the same test file runs against the polyfill (Greenmail) and against genuine ext-imap (`make parity`). Parity is the source of truth — it has caught real asymmetries (c-client's COPY takes the mailbox argument verbatim, while APPEND/STATUS parse the `{host}` prefix off). Practices that keep tests parity-safe:

- Fresh uniquely-named folder per test via `GreenmailTestCase::makeFolder()`; never depend on shared state.
- `makeMsgnoUidMismatchFixture()` when testing UID-flag code paths, so uid≠msgno and the test can't pass by coincidence.
- `\Recent` is session-timing-dependent; consume it with the seeding session (`$seedClient->openFolder(...)`) before asserting unread/recent counts.
- Assertions on polyfill internals (not observable through the real extension) go in `tests/Unit` guarded by `extension_loaded('imap')` skips, not in Integration.
- Tests reading the global error state use the `ResetsErrorStack` trait; "pristine stack" assertions must skip under real ext-imap (no reset hook exists).
- One test class per function: `tests/Integration/Imap<Function>Test.php`.

When implementing a new `imap_*` function, don't work from the manual alone: check `PHP_FUNCTION(...)` in `php_imap.c` (validation, exact ValueError messages, return-value quirks) and c-client sources for wire behavior (e.g. `CP_MOVE` = COPY + `\Deleted`, no expunge — c-client predates the MOVE extension). Constant values come from c-client's `mail.h`. Then update the README Coverage section: add the function to the collapsed `<details>` list of implemented functions, add a row to the divergences table only if behavior deliberately diverges, and bump the count (in the prose, in the `<summary>` line, and in the "missing" sentence).
