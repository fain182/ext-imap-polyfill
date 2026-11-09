<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Message\SortCriterion;
use ImapPolyfill\Message\SortKey;
use PHPUnit\Framework\TestCase;

/**
 * Unit-level for the reason BaseSubjectTest gives: the local sort only runs
 * where the server has no SORT, and over POP3 every internal date is the
 * epoch, so no live fixture can tell a Date: fallback apart.
 */
class SortKeyTest extends TestCase
{
    /**
     * mail_sort_loadcache() falls back to the internal date for a message
     * whose Date: is missing or will not parse.
     */
    public function test_a_message_without_a_usable_date_sorts_by_its_internal_date(): void
    {
        $internalDate = '17-Jul-2026 09:30:00 +0000';

        foreach (["Subject: x\r\n\r\n", "Date: not a date\r\nSubject: x\r\n\r\n"] as $header) {
            $key = SortKey::resolve(
                SortCriterion::Date,
                ['RFC822.HEADER' => $header, 'INTERNALDATE' => $internalDate, 'RFC822.SIZE' => 1],
                'localhost',
            );

            $this->assertSame(strtotime($internalDate), $key);
        }
    }
}
