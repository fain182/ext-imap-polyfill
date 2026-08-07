# Contributing

## Setup

```bash
make install           # composer install
make test-unit         # pure-PHP tests, no server needed
make test-integration  # spins up the fixtures, runs the suite against them, tears them down
make test              # both of the above
make phpstan           # static analysis at level 6
```

Docker or Podman is required from `test-integration` onwards; `docker-compose.yml` describes the same setup for compose tooling.

## The two fixtures

Almost every test runs against **Greenmail** (IMAP on 127.0.0.1:13143, POP3 on 13110, plus imaps and pop3s). A second **Dovecot** fixture (IMAP on 13144, POP3 on 13111) exists for the two commands Greenmail answers `BAD Invalid command` to — THREAD and ACL — and doubles as the second server for `make cross-check`. Its config lives in `tests/fixtures/dovecot.conf`; note the image is rootless, so inside the container it listens on 31143, not 143.

Bring one up on its own with `make greenmail-up` / `make dovecot-up` (and the matching `-down`). Tests needing Dovecot **skip themselves when it isn't running**, so check the skip count before reading a run as green.

## Pointing the suite elsewhere

Every host, port, credential and connection flag is settable: copy `.env.example` to `.env`, which the test bootstrap loads, or export the same names (an exported variable wins over the file).

That is enough to run the Greenmail suite against a real mailbox somewhere, which is worth doing occasionally — it is how the `imap_timeout()` bug surfaced. Build specs with `mailboxSpec()`/`pop3MailboxSpec()` and seed with `seedClient()` so a new test comes along for free; writing `{host:port/imap/novalidate-cert}` by hand instead means cleartext to a TLS port, which does not fail, it waits.

## Verifying against the real extension

```bash
make parity
```

Runs the exact same integration suite a second time, in a PHP 8.3 container with the genuine `ext-imap` installed from source, against the same two fixtures. This is what makes the tests worth anything: it checks that the shapes and behaviour match the extension being replaced, rather than being internally consistent with themselves. The first run builds `Dockerfile.parity`, which takes a few minutes.

**A new test is expected to pass here.** If it can't — because it asserts something only this polyfill exposes — it belongs in `tests/Unit` with an `extension_loaded('imap')` skip.

## Auditing against a second server

```bash
make cross-check
```

Points the Greenmail-targeted suite at Dovecot instead. It is an audit to run before a release, not part of `make test`: a failure means a test, or the polyfill itself, has grown attached to one server's behaviour. That is how the POP3 uid bug surfaced — `imap_uid()` returned the server's UIDL string cast to an integer, which happens to be the message number on Greenmail and is nonsense anywhere else.

A test is expected to pass against either fixture. The ones that genuinely cannot carry `#[Group('greenmail-only')]` **and a docblock saying why** — the trailing newline of `BODY[TEXT]`, `\Recent` timing, Dovecot's read-only quota, its preexisting folders. Leaving a new test untagged is the default on purpose: one that quietly depends on a single server should break this audit.

## Writing the code

When implementing or changing an `imap_*` function, don't work from the PHP manual alone. Check `PHP_FUNCTION(...)` in `php_imap.c` for validation, exact `ValueError` messages and return-value quirks, and the c-client sources for wire behaviour. Constant values come from c-client's `mail.h`.

Fidelity to the real extension — down to error-path return values and stdClass property names, casing and *order* — is the whole point of the project. Where behaviour deliberately differs, say so in a comment at that spot and add a row to the README's divergences table.

`CLAUDE.md` documents the architecture and the reasoning behind the testing rules in more depth.
