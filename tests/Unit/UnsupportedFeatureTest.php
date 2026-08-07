<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Support\UnsupportedFeature;
use PHPUnit\Framework\TestCase;

/**
 * The two things this package cannot do, and refuses loudly rather than
 * approximating.
 *
 * Skipped under the real extension, which does both for real.
 */
class UnsupportedFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped('The real extension speaks NNTP and defines the SCAN family for real.');
        }
    }

    public function test_opening_an_nntp_mailbox_refuses_instead_of_using_imap(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('does not speak NNTP');

        imap_open('{news.example.com:119/nntp}comp.lang.php', 'user', 'pass');
    }

    /**
     * `function_exists()` answers true for these under the real extension, so
     * leaving them undefined would send a caller feature-detecting down a
     * different branch here than there.
     *
     * @return array<string, array{string}>
     */
    public static function scanFunctions(): array
    {
        return [
            'imap_scan' => ['imap_scan'],
            'imap_scanmailbox' => ['imap_scanmailbox'],
            'imap_listscan' => ['imap_listscan'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scanFunctions')]
    public function test_the_scan_family_is_defined_and_says_why_it_refuses(string $function): void
    {
        $this->assertTrue(function_exists($function));

        // Built without connecting: the refusal must come from the function
        // itself, not from the argument check in front of it.
        $connection = new \IMAP\Connection(
            new \ImapPolyfill\Connection\Imap\ImapBackend(
                new \ImapPolyfill\Connection\Imap\ImapEngineConnection(
                    new \DirectoryTree\ImapEngine\Connection\Streams\ImapStream()
                ),
                'example.com',
            ),
            'INBOX',
            '{example.com:143/imap',
            'user',
        );

        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('SCAN');

        $function($connection, '', '*', 'text');
    }
}
