<?php

namespace ImapPolyfill\Tests\Integration;

use ImapPolyfill\Tests\CapturesWarnings;

/**
 * A uid the folder has none of is absent whatever else was wrong with the
 * call. c-client settles that from its own uid cache before it builds a
 * command, so the server never sees the request and never gets to object
 * to it.
 *
 * Greenmail cannot show the difference: it accepts the empty section this
 * test asks for, so the fetch comes back empty and the answer is the same
 * either way. Dovecot rejects it with `NO Invalid BODY [..] section`, which
 * is the wrong answer to give when the message was never there.
 */
final class DovecotAbsentUidTest extends DovecotTestCase
{
    use CapturesWarnings;

    public function test_an_absent_uid_is_reported_before_the_section_is(): void
    {
        $folderName = 'DcAbsentUid'.random_int(10000, 99999);
        $this->makeFolder($folderName)->getFolder($folderName)->appendMessage("Subject: Present\r\n\r\nBody");

        $connection = imap_open(self::mailboxSpec($folderName), self::user(), self::password());

        [$result, $warnings] = $this->capturingWarnings(
            fn () => imap_fetchmime($connection, 99999, '', FT_UID)
        );

        $this->assertFalse($result);
        $this->assertSame(['imap_fetchmime(): UID does not exist'], $warnings);
    }
}
