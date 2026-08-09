<?php

namespace ImapPolyfill\Tests\Integration;

/**
 * Sections inside an embedded message/rfc822, which Greenmail cannot serve:
 * it answers BODY[2] with the wrong sub-part and BODY[2.1] with
 * `NO FETCH failed. java.lang.ClassCastException`.
 *
 * The numbering is the point. The parts of an embedded message are numbered
 * as if the enclosed message were the body part itself (RFC 3501 §6.4.5), so
 * "2.1" is the first part *inside* the attachment — c-client's mail_body()
 * steps into the enclosed body without spending a segment on the step.
 */
final class DovecotEmbeddedMessageSectionsTest extends DovecotTestCase
{
    private function seedCarrier(): string
    {
        $folderName = 'DcEmbedded'.random_int(10000, 99999);
        $this->makeFolder($folderName)->getFolder($folderName)->appendMessage(
            "Subject: Carrier\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"B1\"\r\n"
            ."\r\n"
            ."--B1\r\n"
            ."Content-Type: text/plain\r\n"
            ."\r\n"
            ."Covering note\r\n"
            ."--B1\r\n"
            ."Content-Type: message/rfc822\r\n"
            ."Content-Disposition: attachment; filename=\"forwarded.eml\"\r\n"
            ."\r\n"
            ."Subject: Forwarded\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"B2\"\r\n"
            ."\r\n"
            ."--B2\r\n"
            ."Content-Type: text/plain\r\n"
            ."\r\n"
            ."Enclosed text\r\n"
            ."--B2\r\n"
            ."Content-Type: text/html\r\n"
            ."\r\n"
            ."<b>Enclosed html</b>\r\n"
            ."--B2--\r\n"
            ."--B1--\r\n"
        );

        return $folderName;
    }

    public function test_fetchbody_reaches_the_parts_of_the_enclosed_message(): void
    {
        $connection = imap_open(self::mailboxSpec($this->seedCarrier()), self::user(), self::password());

        $this->assertSame('Covering note', imap_fetchbody($connection, 1, '1'));
        $this->assertSame('Enclosed text', imap_fetchbody($connection, 1, '2.1'));
        $this->assertSame('<b>Enclosed html</b>', imap_fetchbody($connection, 1, '2.2'));
        $this->assertSame('', imap_fetchbody($connection, 1, '2.3'));
    }
}
