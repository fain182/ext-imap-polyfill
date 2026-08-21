<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Connection\UidMode;
use ImapPolyfill\Message\InvalidSequence;
use ImapPolyfill\Message\MessageSequence;
use PHPUnit\Framework\TestCase;

/**
 * How wide a sequence may be before expanding it stops being a fetch. The
 * refusals c-client shares are checked through imap_fetch_overview() in
 * tests/Integration; this ceiling is the polyfill's own.
 */
class MessageSequenceTest extends TestCase
{
    public function test_a_uid_range_no_wider_than_the_folder_is_expanded(): void
    {
        $ids = MessageSequence::parse('1:200000')->expand(200000, UidMode::UID);

        $this->assertCount(200000, $ids);
    }

    /** Uids are sparse, so a range wider than the folder is ordinary. */
    public function test_a_uid_range_wider_than_the_folder_is_still_expanded(): void
    {
        $ids = MessageSequence::parse('1:100000')->expand(3, UidMode::UID);

        $this->assertCount(100000, $ids);
        $this->assertSame(100000, end($ids));
    }

    public function test_a_uid_range_naming_the_whole_number_space_is_refused(): void
    {
        $this->expectException(InvalidSequence::class);
        $this->expectExceptionMessage('Sequence expands to more messages than any mailbox holds');

        MessageSequence::parse('1:4294967295')->expand(3, UidMode::UID);
    }

    /** The allowance is the whole expansion's, not each range's. */
    public function test_repeated_ranges_are_counted_together(): void
    {
        $sequence = implode(',', array_fill(0, 20, '1:100000'));

        $this->expectException(InvalidSequence::class);
        $this->expectExceptionMessage('Sequence expands to more messages than any mailbox holds');

        MessageSequence::parse($sequence)->expand(3, UidMode::UID);
    }

    /** A msgno sequence is bounded by the count long before the ceiling. */
    public function test_a_msgno_range_is_still_refused_by_the_count(): void
    {
        $this->expectException(InvalidSequence::class);
        $this->expectExceptionMessage('Sequence range invalid');

        MessageSequence::parse('1:4294967295')->expand(3);
    }

    public function test_the_sequences_a_caller_writes_are_untouched(): void
    {
        $this->assertSame([1, 2, 3], MessageSequence::parse('1:3')->expand(3));
        $this->assertSame([1, 3], MessageSequence::parse('1,3')->expand(3));
        $this->assertSame([1, 2, 3], MessageSequence::parse('1:*')->expand(3));
        $this->assertSame([7], MessageSequence::parse('7')->expand(3, UidMode::UID));
    }
}
