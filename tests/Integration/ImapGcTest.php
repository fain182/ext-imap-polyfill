<?php

namespace ImapPolyfill\Tests\Integration;

class ImapGcTest extends GreenmailTestCase
{
    public function test_returns_true_with_no_flags(): void
    {
        $folderName = 'GcBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertTrue(imap_gc($connection, 0));
    }

    public function test_returns_true_for_a_single_flag(): void
    {
        $folderName = 'GcBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertTrue(imap_gc($connection, IMAP_GC_TEXTS));
    }

    public function test_returns_true_for_the_full_bitmask(): void
    {
        $folderName = 'GcBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->assertTrue(imap_gc($connection, IMAP_GC_TEXTS | IMAP_GC_ELT | IMAP_GC_ENV));
    }

    public function test_throws_value_error_for_an_invalid_flag(): void
    {
        $folderName = 'GcValBox'.uniqid();
        $this->makeFolder($folderName);
        $connection = imap_open(self::mailboxSpec($folderName), self::USER, self::PASSWORD);

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_gc(): Argument #2 ($flags) must be a bitmask of IMAP_GC_TEXTS, IMAP_GC_ELT, and IMAP_GC_ENV');
        imap_gc($connection, 0x8);
    }
}
