<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\Support\FolderNameRoundTrip;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A folder whose name is not ASCII, carried through every operation that
 * has to name it — created, listed, asked about, appended to, opened,
 * renamed, deleted.
 *
 * Each step asks the same question in a different place: does the name
 * survive? A folder created under one spelling and listed under another
 * is a folder the caller cannot open again, and that failure shows up as
 * a missing mailbox rather than as anything about encodings.
 *
 * The fixture is generated against both fixtures and keeps only what they
 * answer alike (see generate-folder-names.php), so a server's own idea of
 * what a folder may be called stays out of it.
 */
final class FolderNamesTest extends GreenmailTestCase
{
    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function names(): array
    {
        $matrix = require __DIR__.'/../fixtures/folder-names.php';

        $names = FolderNameRoundTrip::names();

        $cases = [];
        foreach ($matrix as $label => $steps) {
            $cases[$label] = [$names[$label], $steps];
        }

        return $cases;
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('names')]
    public function test_survives_every_operation_that_names_it(string $name, array $expected): void
    {
        $carried = FolderNameRoundTrip::carry(
            $name,
            self::host(),
            self::port(),
            self::user(),
            self::password(),
        );

        // Only the steps both servers agreed on were recorded; the rest
        // are the server's answer rather than ext-imap's.
        $this->assertSame($expected, array_intersect_key($carried, $expected));
    }
}
