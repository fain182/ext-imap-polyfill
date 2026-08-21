# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A drop-in polyfill for PHP's `imap_*` functions (removed from core in 8.4), backed by directorytree/imapengine. `bootstrap.php` is a no-op when the real `ext-imap` is loaded; otherwise it defines the same global constants and functions. Fidelity to the real extension — down to error-path return values, `ValueError` messages, and stdClass property names/casing — is the whole point of the project.

## Commands

`CONTRIBUTING.md` is the single source for the make targets, the two fixtures and their ports, and how `make parity` and `make cross-check` work. Read it first; don't restate it here, or the two drift.

The short version: `make test` for unit + integration, `make parity` to run the same integration suite against the genuine extension, `make cross-check` to run it against the second server.

## Architecture

Strict layering; each layer only talks to the next:

- **`src/functions.php`** — conditional (`function_exists`) one-liner shims. No logic beyond delegation, plus the user-facing `trigger_error` warning where ext-imap emits one (e.g. `imap_open`). Aliases (`imap_fetchtext`, `imap_listmailbox`, `imap_delete`…) call the canonical function.
- **`src/Session/`** — the imap_* contract layer, instantiated per call around an `\IMAP\Connection`:
  - `Session` — connection lifecycle: `open()` (static factory, the body of `imap_open`), close, reopen, ping, check, cached counters.
  - `Mailbox` — operations on messages in the currently selected folder (search, fetch*, flags, copy/move, append).
  - `MailboxHierarchy` — folder-level operations, nothing selected (LIST/LSUB, STATUS, create/delete/rename/subscribe).
- **`src/Connection/Connection.php`** (`namespace IMAP`) — polyfill of the opaque native `IMAP\Connection` class. Sole owner of the backend. Knows nothing about imap_* contracts, ErrorStack, or return-value conventions. Two thirds of it is the emulated `MAILSTREAM`: cached message counts (`imap_num_msg` is a cached read that survives a dead connection, like c-client's `stream->nmsgs`), the selected folder and read-only bit, the registered keywords (`stream->user_flags`), half-open, expunge-on-close, and the pieces the reported `Mailbox` string is built from.
  - **What earns a method here.** Only an operation that reads or writes this connection's own state: `check()` and `selectOrExamine()` say nothing to a half-open connection, `selectOrExamineFolder()` registers the keywords the SELECT reported. Every other wire operation is reached through `backend()` directly, on the driver — including the ones that look like they want a courtesy wrapper here. A pass-through method would only put a second name on one contract, and readers would have to learn which of the two names is the one that does something. When adding an operation, ask whether the stream's state changes it; if not, it is the driver's, not this class's.
- **`src/Connection/ConnectionBackend.php`** — the wire operations `IMAP\Connection` needs from whatever protocol it speaks, one implementation per protocol: `Protocol` (IMAP) and `Pop3/Pop3Backend` (POP3).
- **`src/Connection/Protocol.php`** — that interface for IMAP, and the gateway to the raw wire in the same class: it builds every command the polyfill needs and flattens ImapEngine's token trees into plain arrays (NIL as null, numbers as ints, lists as arrays). Commands ImapEngine has no method for — LSUB, msgno-space SEARCH/FETCH/STORE/COPY, SETQUOTA — go out through `Imap\ImapEngineConnection`, a subclass that exposes ImapEngine's otherwise protected send/collect cycle. The two jobs are one class because for IMAP there is nothing in between them: the backend that used to sit here forwarded all but two of its methods to this one, several under a second name. The POP3 side does split them (`Pop3Backend` over `Pop3Protocol`) because there the backend synthesizes the flags, uids and searching the protocol has no wire equivalent for — the same rule as `IMAP\Connection`'s above, one level down.
- **Value objects**: `MailboxSpec` (parses `{host:port/flags}folder` for open/reopen; empty folder defaults to INBOX; throws `ValueError` on malformed input) vs `MailboxReference` (reference/folder arguments of list/append/status: `bareReference` + `displayPrefix`). Don't conflate them.
- **`src/Message/*`** — builders producing the exact stdClass shapes of the real extension (property names, casing, conditional presence).
- **`src/Support/ErrorStack.php`** — process-global static state, deliberately: the real extension has one global error stack (`imap_errors()` takes no connection). `imap_errors()` drains it *and* clears the last error, because in `php_imap.c` both functions read the same stack.

## What a comment is for

This codebase leans on comments, which makes it worth saying which ones earn
their place. A comment that asserts something falsifiable — "some servers
refuse this", "this is the only caller" — is a test nobody wrote: it can go
quietly wrong and nothing notices. One that describes the shape of a value or
the values it may hold is a type nobody declared (see `UidTable`, and the
enum note below). One that explains what an identifier means is a name that
lost an argument. What is left is what the code genuinely cannot say: why an
operation sits at *this* layer rather than one above, what c-client or
php_imap.c does differently, what a dependency's behaviour is relied on. Those
are the ones to write, and they are worth writing at length.

## Error-handling contract (do not "fix" it)

Wrappers replicate ext-imap, not modern taste: catch `\Throwable`, push the message to `ErrorStack`, and return whatever the real function returns on failure — which varies deliberately (`false` for most fetches, `[]` for `imap_fetch_overview`, `true` always for `imap_setflag_full`/`imap_expunge`/`imap_delete`, `0` for `imap_msgno`). Invalid flag bitmasks throw `ValueError` with messages copied from `php_imap.c`'s `zend_argument_value_error` calls. Any call on a closed connection throws `ValueError` via `Connection::ensureOpen()` (`imap_is_open` is the one exception). Divergences from the real extension are documented in comments at the point of divergence and in the README's Coverage divergences table. A divergence you had to *invent* — a limit, a refusal, a wording the extension has no equivalent for — is usually the wrong fix: `MessageSequence` grew an allowance against a uid range that expanded to four billion ids, and the allowance was only needed because the class listed the ids a set spans where `mail_sequence()` marks the messages it names. Porting the mechanism removed the divergence, the DoS, and two behaviours (a message named twice, and the order the answer comes back in) nobody had noticed were wrong.

What lands on the stack matters as much as when. A rejected command records the server's own response text — no tag, no status, no echo of the command — because that is what c-client hands `mm_log()`; `Imap\ImapEngineConnection` re-raises every failure as `CommandFailedException` for exactly that reason, and the same class fills the `imap_alerts()` stack from untagged `[ALERT]` responses (php_imap.c's `mm_notify`). Both overrides look like plumbing and are contract.

**That contract stops at the boundary.** Returning `false` and a stack message is how the polyfill answers its *caller*; it is not how one layer answers another. Inside, a request nobody has taught this code to serve is a bug in the code, and the failure that costs the most is the one that looks served: an unhandled FETCH item that collapsed to `''` had a raw `BODYSTRUCTURE` going out where `fetchBodyStructure()` was waiting, and a SELECT whose count never arrived was read as `$status['exists'] ?? 0` in fourteen places, so any mailbox reported itself empty. Neither showed up as a failure anywhere. So:

- **A fallback exists only where it is the answer.** `Pop3SearchEvaluator`'s unknown keyword matching, `BODYSTRUCTURE`'s unknown type becoming `TYPEOTHER`, `Pop3Backend::fetchSection()`'s default: each is what the real extension does, and each says so in a comment. Where the fallback is a shrug instead, throw — `LogicException` for "no caller can be here", `RuntimeException` for a server that broke its own contract (the wrappers turn that one into the usual `false` plus stack message, so the user-facing contract is unaffected). `Support\UnsupportedFeature` is the loud one for what this package will never do.
- **A closed set crosses a layer as a type.** `int $criteria` forced a default arm into both of `SortKey`'s matches and each invented a plausible ordering; as `Message\SortCriterion` they are exhaustive with no default, and the eighth criterion added without an arm is a PHPStan error at level 6 rather than a silent mis-sort. The imap_* ints stay at the boundary, converted once, where an unknown value is a `ValueError` to raise.
- **Don't hand a required value across a layer in an `array<string, mixed>`.** Nothing can prove a key present, so every reader defends itself, and the cheapest defense is a plausible zero. `Connection\FolderState` is the shape to copy: a constructor that takes what the operation cannot answer without. A keyed array is right only where presence is itself the information — `folderStatus()` returns just the STATUS items that were asked for, and `imap_status()` sets just those properties.

## Testing philosophy

Integration tests are **characterization tests of the real extension** and must be parity-safe: the same test file runs against the polyfill and against genuine ext-imap (`make parity`). Parity is the source of truth — it has caught real asymmetries (c-client's COPY takes the mailbox argument verbatim, while APPEND/STATUS parse the `{host}` prefix off) and fixtures that only work for us (Dovecot advertising STARTTLS: fine for this polyfill, fatal for c-client, which upgrades whenever the server offers it). Practices that keep tests parity-safe:

- Fresh uniquely-named folder per test via `GreenmailTestCase::makeFolder()`; never depend on shared state.
- **A test passes against either fixture unless it says otherwise** — see `make cross-check` in CONTRIBUTING.md for the tagging rule. It is worth the trouble: it is what caught `imap_uid()` over POP3 returning the server's UIDL string cast to int, which is the message number on Greenmail and garbage anywhere else.
- **Let parity settle what you don't know.** Where the extension's behaviour is
  the question, pin what this package currently does in a parity-safe test and
  push: a red `parity` job is the answer, and a green one is coverage you now
  have. Guessing and writing the guess into an assertion is the one thing to
  avoid — that is how a divergence becomes a documented feature.
- **Don't hardcode one server version's behavior.** An `imap_sort(SORTSUBJECT)` test once asserted Greenmail's raw-subject ordering and broke the day upstream implemented RFC 5256 base subjects. Assert the contract instead — compare against what the server itself answers (`SeedClient::sorted()`), or pin the command sent with a `FakeStream` unit test.
- `DovecotTestCase` is standalone on purpose: it shares no base with `GreenmailTestCase`, since the two differ in hierarchy separator (`/` vs `.`), POP3 service and preexisting folders. Put a test there only when Greenmail cannot host it at all; everything else stays on Greenmail.
- `makeMsgnoUidMismatchFixture()` when testing UID-flag code paths, so uid≠msgno and the test can't pass by coincidence.
- `\Recent` is session-timing-dependent; consume it with the seeding session (`$seedClient->openFolder(...)`) before asserting unread/recent counts.
- Assertions on polyfill internals (not observable through the real extension) go in `tests/Unit` guarded by `extension_loaded('imap')` skips, not in Integration.
- Tests reading the global error state use the `ResetsErrorStack` trait; "pristine stack" assertions must skip under real ext-imap (no reset hook exists).
- One test class per function: `tests/Integration/Imap<Function>Test.php`.

**The extension's own suite.** `tests/phpt` is the imap extension's `.phpt`
suite, vendored and run by `make phpt` (see CONTRIBUTING.md). Nobody here wrote
those tests, so they are the one place a divergence shows up that our own
characterization tests cannot: they encode what the extension's authors thought
it did. When one starts failing, the polyfill moved — and when one starts
passing, an `--XFAIL--` reason in that directory is out of date and the target
says so.

**Capability-gated operations.** `imap_sort` and `imap_thread` hand the work to the server whenever CAPABILITY advertises `SORT` / `THREAD=REFERENCES`, and only fall back to the ported c-client algorithms otherwise — matching `imap_sort`/`imap_thread` in `imap4r1.c`, including the retry-locally-on-BAD path. So the same function takes different branches per fixture: server-side on both servers for SORT, server-side only on Dovecot for THREAD, local over POP3. `Protocol::sort()`/`thread()` answer `null` for a BAD rejection, which is what triggers the fallback; the local algorithms themselves are covered in `tests/Unit` (`BaseSubjectTest`) and over POP3.

When implementing a new `imap_*` function, don't work from the manual alone: check `PHP_FUNCTION(...)` in `php_imap.c` (validation, exact ValueError messages, return-value quirks) and c-client sources for wire behavior (e.g. `CP_MOVE` = COPY + `\Deleted`, no expunge — c-client predates the MOVE extension). Constant values come from c-client's `mail.h`. Then update the README Compatibility section: add the function to the implemented-functions list, add a row to the divergences table only if behavior deliberately diverges, and bump the count in both places that carry it (the opening sentence and the "The N implemented functions" heading).
