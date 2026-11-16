<?php

namespace ImapPolyfill\Tests\Unit;

use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use ImapPolyfill\Connection\Imap\ImapEngineTokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tokenizer is only a faster reading of ImapEngine's, so the oracle is
 * ImapEngine's own: every input, cut into reads at every place a server's
 * packets could cut it, must come out as the same tokens or the same
 * exception.
 */
class ImapEngineTokenizerTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function responses(): array
    {
        return [
            'fetch envelope' => ["* 12 FETCH (UID 7 ENVELOPE (\"Tue, 13 Oct 2026 09:10:30 -0400\" \"Re: \\\"Ciao\\\" \\\\ x\" ((\"Jo\" NIL \"jo\" \"example.net\")) NIL NIL NIL NIL NIL \" <a@b>\" \"<self@x>\") BODY[HEADER.FIELDS (REFERENCES)] {12}\r\nReferences:\r\n)\r\n"],
            'blanks and tabs' => ["*  OK \t [ALERT]  text\r\n"],
            'atoms with 8-bit bytes' => ["* LIST (\\HasNoChildren) \"/\" Caf\xC3\xA9\r\n"],
            'nil in any case' => ["* 1 FETCH (X nil Y NiL)\r\n"],
            'email address' => ["* OK <user@example.com> done\r\n"],
            'a number glued to an atom' => ["* 123abc 45 6\r\n"],
            'control byte where an atom starts' => ["* \x01oops\r\n"],
            'unterminated quoted string' => ['* "abc'],
            'unterminated escape' => ['* "abc\\'],
            'unterminated address' => ['* <abc'],
            'atom at the end of the stream' => ['* ABC'],
            'empty quoted string' => ["* \"\" x\r\n"],
        ];
    }

    #[DataProvider('responses')]
    public function test_reads_what_imapengine_reads_wherever_the_reads_are_cut(string $response): void
    {
        for ($cut = 1; $cut <= strlen($response); $cut++) {
            $this->assertSame(
                self::tokens(new ImapTokenizer(self::stream($response, $cut))),
                self::tokens(new ImapEngineTokenizer(self::stream($response, $cut))),
                "reads of {$cut} bytes",
            );
        }
    }

    public function test_reads_what_imapengine_reads_on_random_input(): void
    {
        mt_srand(1794);
        $alphabet = " \t\r\n\"\\()[]{}<>\x00\x7FaZ09NIL\xC3\xA9";

        for ($i = 0; $i < 2000; $i++) {
            $response = '';
            for ($n = mt_rand(1, 40); $n > 0; $n--) {
                $response .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            $cut = mt_rand(1, 8);

            $this->assertSame(
                self::tokens(new ImapTokenizer(self::stream($response, $cut))),
                self::tokens(new ImapEngineTokenizer(self::stream($response, $cut))),
                bin2hex($response)." in reads of {$cut} bytes",
            );
        }
    }

    private static function stream(string $response, int $cut): FakeStream
    {
        $stream = new FakeStream();
        $stream->open();
        $stream->feedRaw(str_split($response, $cut));

        return $stream;
    }

    /**
     * @return list<string>
     */
    private static function tokens(ImapTokenizer $tokenizer): array
    {
        $tokens = [];

        try {
            for ($i = 0; $i < 200 && ($token = $tokenizer->nextToken()) !== null; $i++) {
                $tokens[] = $token::class.' '.bin2hex($token->value);
            }
        } catch (\Throwable $e) {
            $tokens[] = $e::class.': '.$e->getMessage();
        }

        return $tokens;
    }
}
