# The extension's own test suite

The `.phpt` files under `tests/` are the imap extension's own tests, copied
here from [php/pecl-mail-imap](https://github.com/php/pecl-mail-imap) at tag
**1.0.3** — the extension's home since PHP 8.4 dropped it from core. They are
byte-identical to the ones in php-src's PHP-8.3 branch, bar a stray `?>` in
two files. `run-tests.php` is php-src's, from tag **php-8.3.14**; the PECL
package doesn't ship one, and running a `.phpt` needs it.

Both are under the PHP License 3.01, kept in `LICENSE` next to them. This
package is MIT; that file covers this directory only.

## Why they are copied rather than fetched

Nothing upstream moves. PHP 8.3 is security-only until the end of 2027, so
the copy in php-src is frozen, and the PECL package has had no commit since
October 2024. Downloading them per run would trade a directory in the tree
for a network call that can fail, and would buy no freshness.

## What was changed, and why

Three modifications, committed as edits rather than applied by a script, so
what runs is what you can read:

1. **The `--EXTENSIONS--\nimap` header is stripped from all 89 files.**
   run-tests skips a test whose extension isn't loaded, and ext-imap not
   being loaded is the whole point of running these here.
2. **`tests/setup/imap_include.inc` points at this repo's Dovecot fixture**
   (`make dovecot-up`), reached the way `tests/Integration/DovecotTestCase`
   reaches it. Greenmail cannot host these: too many of them need a server
   that behaves like the one php-src's own README asks for.
3. **Three files carry an `--XFAIL--` section**, where what the test asserts
   is the very thing this package cannot do: `NIL` being deprecated
   (`nil_constant`), `null` reaching a string parameter (`bug77020`), and one
   that is the fixture's doing rather than any code's — it accepts anonymous
   login, so there is no error to list, and real ext-imap answers the same
   against it (`imap_errors_basic`).
4. **Six files were edited** where the differing bytes were not what the test
   was asserting. Three are the shape of running as a Composer package on PHP
   8.5: `bug53377` matches object handles with `%d` (they start higher because
   objects of our own are alive), `imap_final` ends in `%A` (the parent class
   is autoloaded, so the engine raises the refusal at runtime, with a stack
   trace), and `imap_fetchbody_basic` writes its own `case 'X':` with a colon,
   PHP 8.5 having deprecated the semicolon it was written with. Three end in
   `%A` to absorb a divergence this package documents rather than asserts here:
   the `, called in ...` a userland TypeError carries (`bug75774`), and the
   error stack not being printed at request shutdown (`bug46918`,
   `imap_open_error`). Everything before the wildcard is still matched
   literally.

An `--XFAIL--` is the last resort, not the tidy answer: a test carrying one is
not checked at all, so a regression in the parts this package *does* get right
would go unnoticed. It is used only where what the test asserts is precisely
what cannot be answered.

## Running them

```bash
make phpt
```

Brings the Dovecot fixture up, runs the suite against the polyfill, and takes
the fixture down again. An `--XFAIL--` test that starts *passing* fails the
target: run-tests only warns about it, and a stale reason in this directory is
worth more than a green run.
