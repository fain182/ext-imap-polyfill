<?php

namespace ImapPolyfill\Tests\Unit;

use ImapPolyfill\Tests\SpeaksToAFakeServer;
use PHPUnit\Framework\TestCase;

/**
 * The uid table is fetched with "1:*", which a folder holding no messages
 * has no answer for and some servers refuse outright.
 */
class ProtocolUidTableTest extends TestCase
{
    use SpeaksToAFakeServer;

    public function test_an_empty_folder_is_not_asked_for_its_uids(): void
    {
        $protocol = $this->protocolServing([
            '* 0 EXISTS',
            'TAG1 OK [READ-WRITE] SELECT completed',
        ]);
        $protocol->selectOrExamineFolder('INBOX', false);

        $this->assertSame([], $protocol->getUid());
        $this->stream->assertNotWritten('FETCH');
    }
}
