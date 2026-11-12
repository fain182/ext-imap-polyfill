# Real mail, read only

```bash
make realmail                                   # .env.realmail
make realmail REALMAIL_ACCOUNT=.env.realmail.gmail
```

The fuzzer's oracle (`tests/fuzz/`) pointed at a real account: the same
questions go to this package and to the genuine extension in the parity
image, and `tests/fuzz/compare.php` diffs the answers. What it adds over the
fixtures is a server nobody here configured and mail nobody here wrote.

Not in CI, and nothing it reads leaves the machine: the credentials are
personal and so is the mail. `.env.realmail*` and `tests/realmail/run/` are
ignored by git. A divergence becomes a characterization test only after it
is reduced and anonymized by hand.

## The account file

An INI file, `.env.realmail` by default:

```ini
REALMAIL_SERVER = "{imap.fastmail.com:993/imap/ssl}"
REALMAIL_USER = "someone@fastmail.com"
REALMAIL_PASSWORD = "an app password"
REALMAIL_FOLDERS = "INBOX"
REALMAIL_LIMIT = 500
```

`REALMAIL_FOLDERS` is a comma-separated list of folder names as the server
lists them; `REALMAIL_LIMIT` is how many of each folder's newest messages
are crawled. Use an app password, not the account's own.

## Read only

Every open is `OP_READONLY`, so the server gets EXAMINE and refuses flag
changes; only reading functions are called; bodies are fetched with
`FT_PEEK` on top of that.

## The three steps

- `snapshot.php` (under the polyfill) fixes the questions: the folders, the
  newest uids of each, the MIME sections of every message, and the address
  and subject headers as the server sent them. Fixed once, because the two
  runs are minutes apart against a mailbox that keeps receiving mail, and
  because an engine that enumerated sections from its own wrong structure
  would change the questions instead of failing an answer.
- `crawl.php` asks them, once per engine.
- `tests/fuzz/compare.php` reports one example per kind of difference.

Folder-wide answers (search, sort) are cut to the uids the snapshot saw.
`imap_thread()` is not, since a tree cannot be pruned without changing its
shape: new mail between the two runs shows up there and only there. Counts
that belong to the session (`\Recent`, `imap_check()`'s date) are not asked.

The NFD filter in `compare.php` needs `intl`; without it the documented
`imap_utf8` divergence is reported as a finding.
