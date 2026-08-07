<?php

namespace ImapPolyfill\Tests\Integration;

/**
 * imap_search()'s $charset argument, against a server that honours it.
 *
 * Greenmail cannot host this: it answers false for a term in any charset,
 * the real extension included, so it can neither show the argument working
 * nor show it being ignored. Dovecot answers both.
 *
 * A term outside ASCII is the only case where the argument is load-bearing
 * — the server has to be told how to read those bytes before it can match
 * them against a message.
 */
final class DovecotSearchCharsetTest extends DovecotTestCase
{
    private const TERM = 'сок';

    private function seedRussianBody(): string
    {
        $folderName = 'DcSearchCharset'.random_int(10000, 99999);
        $this->makeFolder($folderName)->getFolder($folderName)->appendMessage(
            "Subject: Russian\r\n"
            ."From: joe@example.com\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n"
            ."\r\n"
            .'Body containing '.self::TERM." here"
        );

        return $folderName;
    }

    public function test_finds_a_non_ascii_term_in_the_charset_it_was_given(): void
    {
        $connection = imap_open(self::mailboxSpec($this->seedRussianBody()), self::user(), self::password());

        $found = imap_search($connection, 'BODY "'.self::TERM.'"', SE_FREE, 'UTF-8');

        $this->assertSame([1], $found);
    }

    /**
     * The same term written in another charset: the bytes differ, so only a
     * server told which charset they are in can match them.
     */
    public function test_finds_the_same_term_encoded_in_another_charset(): void
    {
        $connection = imap_open(self::mailboxSpec($this->seedRussianBody()), self::user(), self::password());
        $term = mb_convert_encoding(self::TERM, 'Windows-1251', 'UTF-8');

        $found = imap_search($connection, 'BODY "'.$term.'"', SE_FREE, 'Windows-1251');

        $this->assertSame([1], $found);
    }
}
