<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\Support\CharsetCalls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The conversions, one charset at a time, against whichever engine is
 * loaded.
 *
 * Non-ASCII input has been the most productive thing to feed this library:
 * an accented mailbox name and an encoded word in a From header tripped
 * divergences that were not about charsets at all. These are the calls
 * where the bytes *are* the point, so they are worth asking in bulk rather
 * than meeting one report at a time.
 *
 * The calls take no connection, so this runs unchanged under `make
 * parity`, and the fixture it reads was written by that run — see
 * generate-charset-corpus.php, which only produces answers with the
 * genuine extension loaded.
 */
final class CharsetCorpusTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function conversions(): array
    {
        $corpus = require __DIR__.'/../fixtures/charset-corpus.php';

        $cases = [];
        foreach ($corpus as $label => $expected) {
            $cases[$label] = [$label, $expected];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('conversions')]
    public function test_matches_the_real_extension(string $label, array $expected): void
    {
        $calls = CharsetCalls::all();

        $this->assertArrayHasKey($label, $calls, 'the fixture names a call that no longer exists');
        $this->assertSame($expected, CharsetCalls::outcome($calls[$label]));
    }
}
