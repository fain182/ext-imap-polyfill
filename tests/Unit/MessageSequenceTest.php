<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Message\InvalidSequence;
use ImapPolyfill\Message\MessageSequence;
use PHPUnit\Framework\TestCase;

/**
 * A set read against a folder. The refusals c-client shares are checked
 * through imap_fetch_overview() in tests/Integration; these pin what a uid
 * set costs, which is the folder rather than the range it spans.
 */
class MessageSequenceTest extends TestCase
{
    /** A folder of three messages whose uids are nowhere near their msgnos. */
    private const UIDS = [1 => 100, 2 => 20000, 3 => 4000000];

    public function test_a_uid_range_answers_the_uids_the_folder_holds(): void
    {
        $this->assertSame([100, 20000], MessageSequence::parse('1:20000')->uids(self::UIDS));
    }

    /** The whole uid space costs what the folder costs, not four billion. */
    public function test_a_uid_range_naming_the_whole_number_space_is_no_wider_than_the_folder(): void
    {
        $this->assertSame(
            [100, 20000, 4000000],
            MessageSequence::parse('1:4294967295')->uids(self::UIDS),
        );
    }

    /** "*" is the last message's uid, not the message count. */
    public function test_a_star_stands_for_the_highest_uid(): void
    {
        $this->assertSame([20000, 4000000], MessageSequence::parse('20000:*')->uids(self::UIDS));
        $this->assertSame([4000000], MessageSequence::parse('*')->uids(self::UIDS));
    }

    public function test_a_uid_nobody_has_is_absent(): void
    {
        $this->assertSame([], MessageSequence::parse('99999')->uids(self::UIDS));
        $this->assertSame([100], MessageSequence::parse('100,99999')->uids(self::UIDS));
    }

    public function test_an_empty_folder_names_nothing(): void
    {
        $this->assertSame([], MessageSequence::parse('1:*')->uids([]));
        $this->assertSame([], MessageSequence::parse('1:5')->uids([]));
    }

    public function test_a_uid_set_is_still_read_by_its_own_rules(): void
    {
        $this->expectException(InvalidSequence::class);
        $this->expectExceptionMessage('UID may not be zero');

        MessageSequence::parse('0:2')->uids(self::UIDS);
    }

    public function test_message_numbers_are_the_ones_the_set_names(): void
    {
        $this->assertSame([1, 2, 3], MessageSequence::parse('1:3')->messageNumbers(3));
        $this->assertSame([1, 3], MessageSequence::parse('1,3')->messageNumbers(3));
        $this->assertSame([1, 2, 3], MessageSequence::parse('1:*')->messageNumbers(3));
    }

    public function test_a_message_number_past_the_count_is_refused(): void
    {
        $this->expectException(InvalidSequence::class);
        $this->expectExceptionMessage('Sequence range invalid');

        MessageSequence::parse('1:4294967295')->messageNumbers(3);
    }

    /** The count bounds each range, so only a set repeating one can pile up. */
    public function test_a_set_repeating_what_fits_is_refused(): void
    {
        $sequence = implode(',', array_fill(0, 40, '1:3000'));

        $this->expectException(InvalidSequence::class);
        $this->expectExceptionMessage('Sequence expands to more messages than any mailbox holds');

        MessageSequence::parse($sequence)->messageNumbers(3000);
    }
}
