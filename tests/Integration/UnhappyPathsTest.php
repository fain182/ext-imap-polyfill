<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\Support\UnhappyPaths;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every call in tests/Support/UnhappyPaths.php, on a connection that
 * cannot satisfy it, against the answers the real extension gave.
 *
 * The unhappy path is where ext-imap is least like modern PHP and least
 * like itself: imap_fetch_overview() answers [] where its neighbours
 * answer false, the flag and delete calls answer true whether or not the
 * server took them, imap_num_msg() keeps reporting a count it can no
 * longer verify, and imap_is_open() is the one call a closed connection
 * still answers. None of that is guessable, and covering it one function
 * at a time is how a whole state goes untried — a message number past the
 * end of the folder was, until it turned up as a fatal TypeError.
 *
 * The fixture is generated (see generate-unhappy-paths.php, which only
 * runs with the genuine extension loaded), so this asserts real ext-imap
 * against its own recorded behaviour and the polyfill against the same.
 */
final class UnhappyPathsTest extends GreenmailTestCase
{
    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function cells(): array
    {
        $matrix = require __DIR__.'/../fixtures/unhappy-paths.php';

        $cases = [];
        foreach ($matrix as $scenario => $calls) {
            foreach ($calls as $call => $expected) {
                $cases["{$call} on a {$scenario}"] = [$scenario, $call, $expected];
            }
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('cells')]
    public function test_matches_the_real_extension(string $scenario, string $call, array $expected): void
    {
        $connection = UnhappyPaths::scenarios()[$scenario](
            self::host(),
            self::port(),
            self::user(),
            self::password(),
        );

        $this->assertNotFalse($connection, 'the scenario could not open its connection');

        $outcome = UnhappyPaths::outcome(UnhappyPaths::probes()[$call], $connection);

        $this->assertSame($expected, $outcome);
    }
}
