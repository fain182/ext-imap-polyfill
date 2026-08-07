# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A drop-in polyfill for PHP's `imap_*` functions (removed from core in 8.4), backed by directorytree/imapengine. `bootstrap.php` is a no-op when the real `ext-imap` is loaded; otherwise it defines the same global constants and functions. Fidelity to the real extension — down to error-path return values, `ValueError` messages, and stdClass property names/casing — is the whole point of the project.

## Commands

```bash
make install           # composer install
make test-unit         # pure-PHP tests, no server
make test-integration  # spins up disposable Greenmail + Dovecot (podman/docker), runs suite, tears down
make test              # both
make parity            # same integration suite against REAL ext-imap (PHP 8.3 container) + the same two servers
```

Two fixtures, because no single test server covers everything:

- **Greenmail** (`make greenmail-up`/`greenmail-down`, 127.0.0.1:13143 IMAP + 13110 POP3) runs every test class except the `Dovecot*` ones. Override with `IMAP_POLYFILL_TEST_HOST`/`IMAP_POLYFILL_TEST_PORT`.
- **Dovecot** (`make dovecot-up`/`dovecot-down`, 127.0.0.1:13144) exists only for what Greenmail answers `BAD Invalid command` to: THREAD and ACL. `IMAP_POLYFILL_DOVECOT_HOST`/`IMAP_POLYFILL_DOVECOT_PORT`. Config in `tests/fixtures/dovecot.conf`; note the image is rootless, so its listener is on 31143 inside the container, not 143.

Single test: bring up the fixture that class needs, then
`vendor/bin/phpunit --filter test_name tests/Integration/ImapOpenTest.php`.
**A `DovecotTestCase` class with no Dovecot running reports "skipped", not failed** — check the skip count before believing a green run.

## Architecture

Strict layering; each layer only talks to the next:

- **`src/functions.php`** — conditional (`function_exists`) one-liner shims. No logic beyond delegation, plus the user-facing `trigger_error` warning where ext-imap emits one (e.g. `imap_open`). Aliases (`imap_fetchtext`, `imap_listmailbox`, `imap_delete`…) call the canonical function.
- **`src/Session/`** — the imap_* contract layer, instantiated per call around an `\IMAP\Connection`:
  - `Session` — connection lifecycle: `open()` (static factory, the body of `imap_open`), close, reopen, ping, check, cached counters.
  - `Mailbox` — operations on messages in the currently selected folder (search, fetch*, flags, copy/move, append).
  - `MailboxHierarchy` — folder-level operations, nothing selected (LIST/LSUB, STATUS, create/delete/rename/subscribe).
- **`src/Connection/Connection.php`** (`namespace IMAP`) — polyfill of the opaque native `IMAP\Connection` class. Sole owner of the backend; exposes named wire operations. Knows nothing about imap_* contracts, ErrorStack, or return-value conventions. Also holds cached message counts (mirrors c-client's `stream->nmsgs`: `imap_num_msg` is a cached read that survives a dead connection).
- **`src/Connection/Protocol.php`** — Gateway to the raw IMAP wire: builds every command the polyfill needs and flattens ImapEngine's token trees into plain arrays (NIL as null, numbers as ints, lists as arrays). Commands ImapEngine has no method for — LSUB, msgno-space SEARCH/FETCH/STORE/COPY, SETQUOTA — go out through `Imap\ImapEngineConnection`, a subclass that exposes ImapEngine's otherwise protected send/collect cycle.
- **Value objects**: `MailboxSpec` (parses `{host:port/flags}folder` for open/reopen; empty folder defaults to INBOX; throws `ValueError` on malformed input) vs `MailboxReference` (reference/folder arguments of list/append/status: `bareReference` + `displayPrefix`). Don't conflate them.
- **`src/Message/*`** — builders producing the exact stdClass shapes of the real extension (property names, casing, conditional presence).
- **`src/Support/ErrorStack.php`** — process-global static state, deliberately: the real extension has one global error stack (`imap_errors()` takes no connection). `imap_errors()` drains it *and* clears the last error, because in `php_imap.c` both functions read the same stack.

## Error-handling contract (do not "fix" it)

Wrappers replicate ext-imap, not modern taste: catch `\Throwable`, push the message to `ErrorStack`, and return whatever the real function returns on failure — which varies deliberately (`false` for most fetches, `[]` for `imap_fetch_overview`, `true` always for `imap_setflag_full`/`imap_expunge`/`imap_delete`, `0` for `imap_msgno`). Invalid flag bitmasks throw `ValueError` with messages copied from `php_imap.c`'s `zend_argument_value_error` calls. Any call on a closed connection throws `ValueError` via `Connection::ensureOpen()` (`imap_is_open` is the one exception). Divergences from the real extension are documented in comments at the point of divergence and in the README's Coverage divergences table.

What lands on the stack matters as much as when. A rejected command records the server's own response text — no tag, no status, no echo of the command — because that is what c-client hands `mm_log()`; `Imap\ImapEngineConnection` re-raises every failure as `CommandFailedException` for exactly that reason, and the same class fills the `imap_alerts()` stack from untagged `[ALERT]` responses (php_imap.c's `mm_notify`). Both overrides look like plumbing and are contract.

## Testing philosophy

Integration tests are **characterization tests of the real extension** and must be parity-safe: the same test file runs against the polyfill and against genuine ext-imap (`make parity`). Parity is the source of truth — it has caught real asymmetries (c-client's COPY takes the mailbox argument verbatim, while APPEND/STATUS parse the `{host}` prefix off) and fixtures that only work for us (Dovecot advertising STARTTLS: fine for this polyfill, fatal for c-client, which upgrades whenever the server offers it). Practices that keep tests parity-safe:

- Fresh uniquely-named folder per test via `GreenmailTestCase::makeFolder()`; never depend on shared state.
- **Don't hardcode one server version's behavior.** An `imap_sort(SORTSUBJECT)` test once asserted Greenmail's raw-subject ordering and broke the day upstream implemented RFC 5256 base subjects. Assert the contract instead — compare against what the server itself answers (`SeedClient::sorted()`), or pin the command sent with a `FakeStream` unit test.
- `DovecotTestCase` is standalone on purpose: it shares no base with `GreenmailTestCase`, since the two differ in hierarchy separator (`/` vs `.`), POP3 service and preexisting folders. Put a test there only when Greenmail cannot host it at all; everything else stays on Greenmail.
- `makeMsgnoUidMismatchFixture()` when testing UID-flag code paths, so uid≠msgno and the test can't pass by coincidence.
- `\Recent` is session-timing-dependent; consume it with the seeding session (`$seedClient->openFolder(...)`) before asserting unread/recent counts.
- Assertions on polyfill internals (not observable through the real extension) go in `tests/Unit` guarded by `extension_loaded('imap')` skips, not in Integration.
- Tests reading the global error state use the `ResetsErrorStack` trait; "pristine stack" assertions must skip under real ext-imap (no reset hook exists).
- One test class per function: `tests/Integration/Imap<Function>Test.php`.

**Capability-gated operations.** `imap_sort` and `imap_thread` hand the work to the server whenever CAPABILITY advertises `SORT` / `THREAD=REFERENCES`, and only fall back to the ported c-client algorithms otherwise — matching `imap_sort`/`imap_thread` in `imap4r1.c`, including the retry-locally-on-BAD path. So the same function takes different branches per fixture: server-side on both servers for SORT, server-side only on Dovecot for THREAD, local over POP3. `Protocol::sort()`/`thread()` answer `null` for a BAD rejection, which is what triggers the fallback; the local algorithms themselves are covered in `tests/Unit` (`BaseSubjectTest`) and over POP3.

When implementing a new `imap_*` function, don't work from the manual alone: check `PHP_FUNCTION(...)` in `php_imap.c` (validation, exact ValueError messages, return-value quirks) and c-client sources for wire behavior (e.g. `CP_MOVE` = COPY + `\Deleted`, no expunge — c-client predates the MOVE extension). Constant values come from c-client's `mail.h`. Then update the README Compatibility section: add the function to the implemented-functions list, add a row to the divergences table only if behavior deliberately diverges, and bump the count in both places that carry it (the opening sentence and the "The N implemented functions" heading).
