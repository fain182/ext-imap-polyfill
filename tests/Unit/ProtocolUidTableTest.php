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

        $this->assertSame([], $protocol->uidTable()->uids());
        $this->stream->assertNotWritten('FETCH');
    }

    public function test_an_expunge_and_an_append_edit_the_table_rather_than_replace_it(): void
    {
        $protocol = $this->protocolServing([
            '* 2 EXISTS',
            'TAG1 OK [READ-WRITE] SELECT completed',
            '* 1 FETCH (UID 10)',
            '* 2 FETCH (UID 11)',
            'TAG2 OK FETCH completed',
            '* 1 EXPUNGE',
            '* 2 EXISTS',
            'TAG3 OK NOOP completed',
            '* 2 FETCH (UID 12)',
            'TAG4 OK FETCH completed',
        ]);
        $protocol->selectOrExamineFolder('INBOX', false);
        $this->assertSame([10, 11], $protocol->uidTable()->uids());

        $protocol->noop();

        $table = $protocol->uidTable();
        $this->assertSame([11, 12], $table->uids());
        $this->assertSame(12, $table->uidOf(2));
        $this->assertSame(1, $table->msgnoOf(11));
        $this->stream->assertWritten('TAG4 FETCH 2:2 (UID)');
    }
}
