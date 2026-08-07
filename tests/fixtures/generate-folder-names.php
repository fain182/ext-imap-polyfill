<?php

/**
 * Regenerates tests/fixtures/folder-names.php by carrying each name in
 * tests/Support/FolderNameRoundTrip.php through the genuine ext-imap.
 *
 *     make greenmail-up && make dovecot-up && make parity-build
 *     podman run --rm --network ext-imap-polyfill-net \
 *         -e IMAP_POLYFILL_TEST_HOST=greenmail -e IMAP_POLYFILL_TEST_PORT=3143 \
 *         -e IMAP_POLYFILL_DOVECOT_HOST=dovecot -e IMAP_POLYFILL_DOVECOT_PORT=31143 \
 *         -v "$PWD":/app:Z ext-imap-polyfill-parity \
 *         php tests/fixtures/generate-folder-names.php
 *
 * Both fixtures are measured and only the steps they answer alike are
 * kept, so what is recorded is ext-imap's behaviour rather than one
 * server's idea of what a folder may be called.
 */
require __DIR__.'/../../vendor/autoload.php';

use ImapPolyfill\Tests\Support\FixtureExport;
use ImapPolyfill\Tests\Support\FolderNameRoundTrip;

if (!extension_loaded('imap')) {
    fwrite(STDERR, "Refusing to generate: ext-imap is not loaded, so these would be the polyfill's own answers.\n");

    exit(1);
}

$user = getenv('IMAP_POLYFILL_TEST_USER') ?: 'testuser';
$password = getenv('IMAP_POLYFILL_TEST_PASSWORD') ?: 'testpass';

$measure = static function (string $host, int $port) use ($user, $password): array {
    $matrix = [];

    foreach (FolderNameRoundTrip::names() as $label => $name) {
        $matrix[$label] = FolderNameRoundTrip::carry($name, $host, $port, $user, $password);
    }

    return $matrix;
};

$primary = $measure(
    getenv('IMAP_POLYFILL_TEST_HOST') ?: '127.0.0.1',
    (int) (getenv('IMAP_POLYFILL_TEST_PORT') ?: 13143),
);
$second = $measure(
    getenv('IMAP_POLYFILL_DOVECOT_HOST') ?: '127.0.0.1',
    (int) (getenv('IMAP_POLYFILL_DOVECOT_PORT') ?: 13144),
);

$matrix = [];
$excluded = [];

foreach ($primary as $label => $steps) {
    foreach ($steps as $step => $outcome) {
        if ($outcome === ($second[$label][$step] ?? null)) {
            $matrix[$label][$step] = $outcome;

            continue;
        }

        $excluded[] = "{$step} for {$label}";
    }
}

$omitted = $excluded === []
    ? ' * The two servers agreed on every step.'
    : " * Left out, because the two servers disagree and the answer is\n * therefore theirs rather than ext-imap's:\n *\n *   - ".implode("\n *   - ", $excluded);

$header = <<<PHP
<?php

/**
 * GENERATED FILE — do not edit by hand.
 *
 * How the genuine ext-imap carries a folder name through create, list,
 * status, append, open, rename and delete. Only steps both fixtures
 * answer alike are kept.
 *
{$omitted}
 *
 * generate-folder-names.php is how to regenerate it; FolderNamesTest
 * asserts both engines against it.
 */

return
PHP;

file_put_contents(__DIR__.'/folder-names.php', $header.' '.FixtureExport::render($matrix).";\n");

printf("Wrote %d steps, left out %d the two servers disagree on.\n", array_sum(array_map('count', $matrix)), count($excluded));
