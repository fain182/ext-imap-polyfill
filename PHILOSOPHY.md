# How identical to ext-imap

The aim is that code written against ext-imap keeps working unchanged. That
gives the line: what a caller can observe and rely on must match, and what no
caller can tell apart does not need to match.

## Identical, and checked by `make parity`

- Return values, error paths included: `false`, `[]`, `0`, or `true` always,
  whichever the extension returns.
- The shape of every stdClass: property names, their casing, and when a
  property is present.
- `ValueError` messages, warning text, and what lands on the `imap_errors()`
  and `imap_alerts()` stacks.
- Mail semantics: which messages a search matches, sort order, how threads
  group, how headers and bodies decode, and which operations fail.
- c-client's quirks, when they are deterministic and observable, even when
  they look like bugs: callers may depend on them. Crashes, leaks and
  undefined behaviour are not replicated.

## Same behaviour, form free

- The same text in a different byte form (NFC or NFD, case canonicalization
  of decoded words). This is a mail library, not a Unicode library: no
  porting of c-client's charset tables.
- What goes over the wire: the exact commands, how many round trips, and in
  what order. The server is not the caller.
- Performance has to be reasonable, not the same.

## Userland limits, accepted and documented once

What a PHP function cannot do that an internal one can: `E_USER_WARNING`
instead of `E_WARNING`, `TypeError` on `null`, no notices at request
shutdown. These are listed in the README and not worked around.

## Out of scope, refused loudly

- Newsgroups, in every form: NNTP (`{host/nntp}`), and the Usenet headers
  `Newsgroups:` and `Followup-To:`, which `imap_headerinfo()` does not copy
  into `newsgroups` and `followup_to` as the extension does. This package is
  for mailboxes.
- SASL authentication, SMTP, and `imap_scan()`.
- State that outlives a request, such as c-client's tables surviving across
  FPM requests.

Refused means a `Support\UnsupportedFeature`, never something that quietly
does something else.

## When to diverge on purpose

Only where the extension's behaviour depends on the environment (what DNS
answers, which TLS versions OpenSSL allows) or is unsafe. Never because
another behaviour looks better. A divergence you had to invent is usually the
wrong fix; see the error-handling contract in `CLAUDE.md`.

Every deliberate divergence gets a row in the README table, unless it is too
technical for any caller to depend on.
