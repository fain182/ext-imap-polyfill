# Differential fuzzing

```bash
make fuzz                              # seed 1, 2000 mutations
make fuzz FUZZ_SEED=7 FUZZ_COUNT=20000
```

The same corpus goes through this package and through the genuine extension
in the parity image, and the two sets of answers are diffed. What makes it
worth doing here is that the oracle is exact: not "did it crash", but "did
it answer the same bytes, in the same order, with the same error stack".

Not part of `make test`. It needs the parity image, it takes minutes, and a
run that finds something has done its job the moment the case is reduced —
what belongs in the suite afterwards is a characterization test in
`tests/Integration`, not a fuzzer nobody runs twice.

## The four pieces

- `generate-corpus.php` writes the inputs, one hex-encoded per line. Random
  bytes are close to worthless against these parsers — they never survive
  the first `=?` or the first angle bracket — so every input is a *seeded*
  mutation: the recorded inputs of `tests/fixtures/rfc822-corpus.php` plus a
  handful of headers, damaged the way headers are damaged in the wild
  (a bracket that never closes, padding in the wrong place, a raw 8-bit
  byte, two addresses with no comma between them).
- `calls.php` is what each input is fed to. Connectionless functions only: a
  fuzzer pointed at a live folder would be testing the server's mood as much
  as this package.
- `evaluate.php` runs the calls and prints the answers. It knows nothing
  about which engine it is under — the same file runs on both sides.
- `compare.php` diffs them and reports one shortest input per *kind* of
  difference. The bucketing matters: keyed on the answers themselves, a
  thousand mutations of one bug read as a thousand findings.

The corpus is a file rather than a generator each side runs because the two
sides are different PHP versions in different containers: handing them the
same bytes is the only way to be sure they saw the same bytes. Same
`FUZZ_SEED`, same corpus, so a run that found something can be run again.

## Reading the report

```
[utf8] hit 191 times, shortest input:
  input    '=?UTF-8?B??=]'
  polyfill ']'
  real     '=?UTF-8?B??=]'
```

The input is already close to minimal — the mutations are small and the
shortest of a bucket is kept — but it is not reduced. Reducing it by hand,
against `podman run --rm -v "$PWD":/app:Z ext-imap-polyfill-parity php -r
'…'`, is what turns a bucket into a test.

`compare.php` exits non-zero while anything is left, so `make fuzz` stays
red until the findings it reports are answered.

## What is filtered

One difference is set aside rather than reported: c-client canonicalises to
decomposed UTF-8 (php_imap.c passes `U8T_DECOMPOSE`) where this package
returns the text as its charset wrote it. It is in the README's divergences
table, and it would otherwise be most of the report. `compare.php`
normalizes this package's side before deciding, so a *different* difference
in the same answer still shows up.

Everything else is reported, including differences in the error stack and in
warning text. Severity is not compared: this package raises `E_USER_WARNING`
where the extension raises `E_WARNING`, and no userland function can do
otherwise.
