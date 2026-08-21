<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Connection\UidTable;
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
    /** A folder whose uids are nowhere near the message numbers holding them. */
    private function folder(int ...$uids): UidTable
    {
        $byMsgno = [];
        foreach ($uids as $index => $uid) {
            $byMsgno[$index + 1] = $uid;
        }

        return new UidTable($byMsgno);
    }

    public function test_a_uid_range_answers_the_uids_the_folder_holds(): void
    {
        $this->assertSame([100, 20000], MessageSequence::parse('1:20000')->uids($this->folder(100, 20000, 4000000)));
    }

    /** The whole uid space costs what the folder costs, not four billion. */
    public function test_a_uid_range_naming_the_whole_number_space_is_no_wider_than_the_folder(): void
    {
        $this->assertSame(
            [100, 20000, 4000000],
            MessageSequence::parse('1:4294967295')->uids($this->folder(100, 20000, 4000000)),
        );
    }

    /** "*" is the last message's uid, not the message count. */
    public function test_a_star_stands_for_the_highest_uid(): void
    {
        $this->assertSame([20000, 4000000], MessageSequence::parse('20000:*')->uids($this->folder(100, 20000, 4000000)));
        $this->assertSame([4000000], MessageSequence::parse('*')->uids($this->folder(100, 20000, 4000000)));
    }

    public function test_a_uid_nobody_has_drops_out_of_the_list(): void
    {
        $this->assertSame([100], MessageSequence::parse('100,99999')->uids($this->folder(100, 20000, 4000000)));
    }

    public function test_an_empty_folder_names_nothing(): void
    {
        $this->assertSame([], MessageSequence::parse('1:*')->uids($this->folder()));
        $this->assertSame([], MessageSequence::parse('1:5')->uids($this->folder()));
    }

    public function test_message_numbers_are_the_ones_the_set_names(): void
    {
        $this->assertSame([1, 2, 3], MessageSequence::parse('1:3')->messageNumbers(3));
        $this->assertSame([1, 3], MessageSequence::parse('1,3')->messageNumbers(3));
        $this->assertSame([1, 2, 3], MessageSequence::parse('1:*')->messageNumbers(3));
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
