<?php

/**
 * Regenerates tests/fixtures/unhappy-paths.php by asking the genuine
 * ext-imap what it does when there is nothing to answer with.
 *
 *     make greenmail-up && make parity-build
 *     podman run --rm --network ext-imap-polyfill-net \
 *         -e IMAP_POLYFILL_TEST_HOST=greenmail -e IMAP_POLYFILL_TEST_PORT=3143 \
 *         -v "$PWD":/app:Z ext-imap-polyfill-parity \
 *         php tests/fixtures/generate-unhappy-paths.php
 *
 * Like the RFC 822 corpus, it refuses to run without the real extension:
 * a matrix recorded from the polyfill would enshrine this project's own
 * behaviour and the test built on it could never fail.
 *
 * The scenarios and the calls both live in tests/Support/UnhappyPaths.php,
 * so add there and rerun; never edit the generated file by hand.
 */
require __DIR__.'/../../vendor/autoload.php';

use ImapPolyfill\Tests\Support\AgreedMatrix;
use ImapPolyfill\Tests\Support\FixtureExport;
use ImapPolyfill\Tests\Support\UnhappyPaths;

if (!extension_loaded('imap')) {
    fwrite(STDERR, "Refusing to generate: ext-imap is not loaded, so this would be the polyfill's own behaviour.\n");

    exit(1);
}

$user = getenv('IMAP_POLYFILL_TEST_USER') ?: 'testuser';
$password = getenv('IMAP_POLYFILL_TEST_PASSWORD') ?: 'testpass';

[$matrix, $excluded] = AgreedMatrix::of(
    static function (string $host, int $port) use ($user, $password): array {
        $matrix = [];

        foreach (UnhappyPaths::scenarios() as $scenario => $build) {
            foreach (UnhappyPaths::probes() as $call => $probe) {
                // A connection per cell: several of these calls change the
                // state the next one would be reading.
                $connection = $build($host, $port, $user, $password);

                if ($connection === false) {
                    fwrite(STDERR, "Could not build the '{$scenario}' connection on {$host}:{$port}.\n");

                    exit(1);
                }

                $matrix[$scenario][$call] = UnhappyPaths::outcome($probe, $connection);
            }
        }

        return $matrix;
    },
    '%s on a %s',
);

$omitted = AgreedMatrix::omissionNote($excluded);

$header = <<<PHP
<?php

/**
 * GENERATED FILE — do not edit by hand.
 *
 * What the genuine ext-imap returns, throws and records for each call in
 * tests/Support/UnhappyPaths.php made on a connection that cannot satisfy
 * it. Only cells both fixtures answer alike are kept, so what is here is
 * ext-imap's behaviour and not one server's.
 *
{$omitted}
 *
 * generate-unhappy-paths.php is how to regenerate it; UnhappyPathsTest
 * asserts both engines against it.
 */

return
PHP;

file_put_contents(__DIR__.'/unhappy-paths.php', $header.' '.FixtureExport::render($matrix).";\n");

printf("Wrote %d cells, left out %d the two servers disagree on.\n", array_sum(array_map('count', $matrix)), count($excluded));
