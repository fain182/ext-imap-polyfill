<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A corpus of pathological headers, run against whichever engine is loaded.
 *
 * The functions here take no connection, so the whole class runs unchanged
 * under `make parity` — and the fixture it reads was written *by* that run:
 * tests/fixtures/generate-rfc822-corpus.php only produces answers with the
 * genuine extension loaded, and refuses otherwise. So this asserts real
 * ext-imap against its own recorded behaviour, and the polyfill against the
 * same, from one file.
 *
 * It exists because the divergences found one at a time all had the same
 * shape: input where a regex-shaped parser and c-client's character scanner
 * part company — comments (which nest, and so are not a regular language at
 * all), backslash escapes, folding, a name touching the bracket after it.
 * Covering the neighbourhood in bulk beats meeting its members one by one.
 */
final class Rfc822CorpusTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function corpus(): array
    {
        return require __DIR__.'/../fixtures/rfc822-corpus.php';
    }

    /**
     * @return array<string, array{string, array<int, array<string, string>>}>
     */
    public static function addressLists(): array
    {
        return array_map(
            static fn (array $case): array => [$case['input'], $case['expected']],
            self::corpus()['adrlist'],
        );
    }

    /**
     * @return array<string, array{string, array<int, array{0: string, 1: string}>|false}>
     */
    public static function mimeHeaders(): array
    {
        return array_map(
            static fn (array $case): array => [$case['input'], $case['expected']],
            self::corpus()['mime_header_decode'],
        );
    }

    /**
     * @param array<int, array<string, string>> $expected
     */
    #[DataProvider('addressLists')]
    public function test_address_list_parsing(string $input, array $expected): void
    {
        $parsed = @imap_rfc822_parse_adrlist($input, 'default.host');

        // Fields c-client left unset are absent rather than null, and it
        // sets them in its own order — get_object_vars keeps both visible.
        $this->assertSame(
            $expected,
            array_map(static fn (\stdClass $a): array => get_object_vars($a), $parsed),
        );
    }

    /**
     * @param array<int, array{0: string, 1: string}>|false $expected
     */
    #[DataProvider('mimeHeaders')]
    public function test_mime_header_decoding(string $input, array|false $expected): void
    {
        $decoded = @imap_mime_header_decode($input);

        if ($expected === false) {
            $this->assertFalse($decoded);

            return;
        }

        $this->assertIsArray($decoded);
        $this->assertSame(
            $expected,
            array_map(static fn (\stdClass $p): array => [$p->charset, $p->text], $decoded),
        );
    }
}
