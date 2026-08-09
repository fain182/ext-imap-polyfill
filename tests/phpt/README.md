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
3. **Ten files carry an `--XFAIL--` section** saying why the polyfill cannot
   answer them. Nine are things PHP will not let a userland function do
   (raise E_WARNING's location from a shutdown notice, deprecate a `define()`d
   constant, coerce `null` to a string, keep `, called in ...` off a
   TypeError, control var_dump's object handles) or differences between PHP
   8.5 and the 8.3 these were written against; the tenth is a property of the
   fixture, where real ext-imap answers exactly as this package does.

## Running them

```bash
make phpt
```

Brings the Dovecot fixture up, runs the suite against the polyfill, and takes
the fixture down again. An `--XFAIL--` test that starts *passing* fails the
target: run-tests only warns about it, and a stale reason in this directory is
worth more than a green run.
