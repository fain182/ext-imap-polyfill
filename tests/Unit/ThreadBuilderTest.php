<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Message\ThreadBuilder;
use PHPUnit\Framework\TestCase;

class ThreadBuilderTest extends TestCase
{
    /**
     * Every top-level thread of a folder is a sibling of the others, so a
     * large folder is one very long branch chain. Walking it by recursion
     * runs out of C stack long before the extension does.
     */
    public function test_flattens_a_folder_of_two_hundred_thousand_threads(): void
    {
        $groups = array_map(static fn (int $msgno): array => [$msgno], range(1, 200000));

        $tree = ThreadBuilder::flatten(ThreadBuilder::containersFromServer($groups, false), false);

        $this->assertCount(600000, $tree);
        $this->assertSame(['0.num' => 1, '0.next' => 0, '0.branch' => 1], array_slice($tree, 0, 3));
        $this->assertSame(
            ['199999.num' => 200000, '199999.next' => 0, '199999.branch' => 0],
            array_slice($tree, -3),
        );
    }
}
