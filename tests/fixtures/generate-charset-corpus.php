<?php

/**
 * Regenerates tests/fixtures/charset-corpus.php by reading the answers off
 * the genuine ext-imap.
 *
 *     make parity-build
 *     podman run --rm -v "$PWD":/app:Z ext-imap-polyfill-parity \
 *         php tests/fixtures/generate-charset-corpus.php
 *
 * Refuses to run without the real extension, for the same reason the other
 * generators do: a corpus made from the polyfill would record this
 * project's own bugs as the standard.
 *
 * The calls themselves live in tests/Support/CharsetCalls.php, shared with
 * the test that replays them.
 */
require __DIR__.'/../../vendor/autoload.php';

use ImapPolyfill\Tests\Support\CharsetCalls;
use ImapPolyfill\Tests\Support\FixtureExport;

if (!extension_loaded('imap')) {
    fwrite(STDERR, "Refusing to generate: ext-imap is not loaded, so these would be the polyfill's own answers.\n");

    exit(1);
}

$documented = CharsetCalls::documentedDivergences();
$corpus = [];

foreach (CharsetCalls::all() as $label => $call) {
    if (in_array($label, $documented, true)) {
        continue;
    }

    $corpus[$label] = CharsetCalls::outcome($call);
}

$omitted = ' *   - '.implode("\n *   - ", $documented);

$header = <<<PHP
<?php

/**
 * GENERATED FILE — do not edit by hand.
 *
 * What the genuine ext-imap answers for each call in
 * tests/Support/CharsetCalls.php. generate-charset-corpus.php is how to
 * regenerate it; CharsetCorpusTest asserts both engines against it.
 *
 * Byte values are escaped rather than written raw: several of these
 * answers are not valid UTF-8, and some are not meant to be.
 *
 * Left out, because the difference is the decomposed UTF-8 c-client
 * returns and matching it would mean carrying a Unicode decomposition
 * table; see the imap_utf8 row of the README's table:
 *
{$omitted}
 */

return
PHP;

file_put_contents(__DIR__.'/charset-corpus.php', $header.' '.FixtureExport::render($corpus).";\n");

printf("Wrote %d calls, left out %d written down instead.\n", count($corpus), count($documented));
