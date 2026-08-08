<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\Support\MessageBodies;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Bodies that are not ASCII, read back every way the extension offers.
 *
 * A body's charset is reported in one place and its bytes in another:
 * imap_fetchstructure() names the charset and decodes nothing,
 * imap_body() and imap_fetchbody() hand over the encoded bytes untouched,
 * and the caller is left to put the two together. Any of the three being
 * wrong gives that caller mojibake with nothing to point at, which is why
 * the parameters are asserted as a list and the bytes verbatim.
 *
 * The fixture is generated against both fixtures and keeps only what they
 * answer alike (see generate-message-bodies.php) — a server is free to
 * store a message with the line endings it prefers, and two of them do.
 */
final class MessageBodiesTest extends GreenmailTestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function messages(): array
    {
        $matrix = require __DIR__.'/../fixtures/message-bodies.php';

        $messages = MessageBodies::messages();

        $cases = [];
        foreach ($matrix as $label => $reads) {
            $cases[$label] = [$messages[$label], $reads];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('messages')]
    public function test_reads_back_as_the_real_extension_does(string $raw, array $expected): void
    {
        $read = MessageBodies::read(
            $raw,
            self::host(),
            self::port(),
            self::user(),
            self::password(),
        );

        $this->assertSame($expected, array_intersect_key($read, $expected));
    }
}
