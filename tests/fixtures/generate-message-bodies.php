<?php

/**
 * Regenerates tests/fixtures/message-bodies.php by reading each message in
 * tests/Support/MessageBodies.php back through the genuine ext-imap.
 *
 *     make greenmail-up && make dovecot-up && make parity-build
 *     podman run --rm --network ext-imap-polyfill-net \
 *         -e IMAP_POLYFILL_TEST_HOST=greenmail -e IMAP_POLYFILL_TEST_PORT=3143 \
 *         -e IMAP_POLYFILL_DOVECOT_HOST=dovecot -e IMAP_POLYFILL_DOVECOT_PORT=31143 \
 *         -v "$PWD":/app:Z ext-imap-polyfill-parity \
 *         php tests/fixtures/generate-message-bodies.php
 *
 * Only the reads both fixtures answer alike are kept; see AgreedMatrix.
 */
require __DIR__.'/../../vendor/autoload.php';

use ImapPolyfill\Tests\Support\AgreedMatrix;
use ImapPolyfill\Tests\Support\FixtureExport;
use ImapPolyfill\Tests\Support\MessageBodies;

if (!extension_loaded('imap')) {
    fwrite(STDERR, "Refusing to generate: ext-imap is not loaded, so these would be the polyfill's own answers.\n");

    exit(1);
}

$user = getenv('IMAP_POLYFILL_TEST_USER') ?: 'testuser';
$password = getenv('IMAP_POLYFILL_TEST_PASSWORD') ?: 'testpass';

[$matrix, $excluded] = AgreedMatrix::of(
    static function (string $host, int $port) use ($user, $password): array {
        $matrix = [];

        foreach (MessageBodies::messages() as $label => $raw) {
            $matrix[$label] = MessageBodies::read($raw, $host, $port, $user, $password);
        }

        return $matrix;
    },
    '%s for %s',
);

$omitted = AgreedMatrix::omissionNote($excluded);

$header = <<<PHP
<?php

/**
 * GENERATED FILE — do not edit by hand.
 *
 * What the genuine ext-imap reports for each message in
 * tests/Support/MessageBodies.php: the structure a caller reads the
 * charset from, and the bytes the fetches hand back.
 *
{$omitted}
 *
 * generate-message-bodies.php is how to regenerate it; MessageBodiesTest
 * asserts both engines against it.
 */

return
PHP;

file_put_contents(__DIR__.'/message-bodies.php', $header.' '.FixtureExport::render($matrix).";\n");

printf("Wrote %d reads, left out %d the two servers disagree on.\n", array_sum(array_map('count', $matrix)), count($excluded));
