# How identical to ext-imap

The aim is that code written against ext-imap keeps working unchanged. That
gives the line: what a caller can observe and rely on must match, and what no
caller can tell apart does not need to match.

## Mail that exists, not mail that could

The target is the mail people actually send and receive today. That
includes mail broken the way real mail is broken: badly encoded words,
malformed address lists, a wrong charset, spam. A decoder is tested on
exactly those, and most fixes so far were for them.

It does not include mail that only exists in a spec: Usenet headers, a
message with no `Date:`, constructs no client has sent in decades. A
divergence that shows only on such input is not worth a port, a test or a
README row. When in doubt, ask whether a mail client or mailing list in use
today produces it.

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

A defect is not worth porting when matching it would take more code only
to answer worse. Imitate what a caller reads: the order, the shape, the
markers, however odd. Do not build machinery to lose what the caller asked
for. `imap_fetch_overview()` cuts the From and To lists short in c-client's
envelope cache, so a later `imap_headerinfo()` on the same message reports
one recipient; matching it would mean state carried from one call to the
next, kept for no other purpose. Code written against ext-imap does not rely
on that and works the same with every recipient there, so it is not
replicated, and not listed as a divergence either.

The cost that counts is the cost of the defect, not of the feature around
it. Porting c-client's address parser was expensive, but `from` needs it
anyway; writing `fromaddress` back out of the parsed list instead of
copying the header text is one call on top, so it is matched, markers and
all.

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
