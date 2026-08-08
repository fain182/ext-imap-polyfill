<?php

namespace ImapPolyfill\Tests\Support;

/**
 * Messages whose bodies are not ASCII, and the calls that have to read
 * them back.
 *
 * The charset of a body lives in three places at once — the Content-Type
 * parameter, the transfer encoding that got the bytes through, and the
 * bytes themselves — and the imap_* functions report all three separately.
 * imap_fetchstructure() names the charset without decoding anything,
 * imap_body() and imap_fetchbody() hand back the encoded bytes untouched,
 * and it is the caller who puts the two together. Getting any of them
 * wrong gives a caller mojibake with nothing to point at.
 *
 * Shared by the generator and the test so a recorded answer and a replayed
 * one come from the same message.
 */
final class MessageBodies
{
    private const TEXT = 'caffè Привет 你好';

    /**
     * @return array<string, string> raw messages, keyed by what makes each
     *                               one worth sending
     */
    public static function messages(): array
    {
        $latin1 = (string) iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'caffè');
        $cp1251 = (string) iconv('UTF-8', 'Windows-1251', 'Привет');

        return [
            'utf-8 8bit' => self::simple('text/plain; charset=UTF-8', '8bit', self::TEXT),
            'iso-8859-1 quoted-printable' => self::simple(
                'text/plain; charset=ISO-8859-1',
                'quoted-printable',
                quoted_printable_encode($latin1),
            ),
            'windows-1251 base64' => self::simple(
                'text/plain; charset=Windows-1251',
                'base64',
                chunk_split(base64_encode($cp1251), 76, "\r\n"),
            ),
            'no charset declared' => self::simple('text/plain', '8bit', self::TEXT),
            'charset nobody has' => self::simple('text/plain; charset=X-IAS-German', '8bit', self::TEXT),
            'quoted charset' => self::simple('text/plain; charset="UTF-8"', '8bit', self::TEXT),

            'multipart, two charsets' => "Subject: Both\r\n"
                ."From: joe@example.com\r\n"
                ."MIME-Version: 1.0\r\n"
                ."Content-Type: multipart/alternative; boundary=\"sep\"\r\n"
                ."\r\n"
                ."--sep\r\n"
                ."Content-Type: text/plain; charset=UTF-8\r\n"
                ."Content-Transfer-Encoding: 8bit\r\n"
                ."\r\n"
                .self::TEXT."\r\n"
                ."--sep\r\n"
                ."Content-Type: text/html; charset=ISO-8859-1\r\n"
                ."Content-Transfer-Encoding: quoted-printable\r\n"
                ."\r\n"
                .quoted_printable_encode('<p>'.$latin1.'</p>')."\r\n"
                ."--sep--\r\n",

            'attachment named with an encoded word' => "Subject: Attached\r\n"
                ."From: joe@example.com\r\n"
                ."MIME-Version: 1.0\r\n"
                ."Content-Type: multipart/mixed; boundary=\"sep\"\r\n"
                ."\r\n"
                ."--sep\r\n"
                ."Content-Type: text/plain; charset=UTF-8\r\n"
                ."\r\n"
                ."see attached\r\n"
                ."--sep\r\n"
                ."Content-Type: application/octet-stream; name=\"=?UTF-8?B?"
                    .base64_encode('relazione finanziaria.pdf')."?=\"\r\n"
                ."Content-Disposition: attachment; filename=\"=?UTF-8?B?"
                    .base64_encode('relazione finanziaria.pdf')."?=\"\r\n"
                ."Content-Transfer-Encoding: base64\r\n"
                ."\r\n"
                .base64_encode('not really a pdf')."\r\n"
                ."--sep--\r\n",

            'attachment named across continuations' => "Subject: Split name\r\n"
                ."From: joe@example.com\r\n"
                ."MIME-Version: 1.0\r\n"
                ."Content-Type: multipart/mixed; boundary=\"sep\"\r\n"
                ."\r\n"
                ."--sep\r\n"
                ."Content-Type: text/plain; charset=UTF-8\r\n"
                ."\r\n"
                ."see attached\r\n"
                ."--sep\r\n"
                ."Content-Type: application/octet-stream;\r\n"
                ." name*0*=UTF-8''relazione%20;\r\n"
                ." name*1*=finanziaria.pdf\r\n"
                ."Content-Transfer-Encoding: base64\r\n"
                ."\r\n"
                .base64_encode('not really a pdf')."\r\n"
                ."--sep--\r\n",
        ];
    }

    /**
     * Seeds one message in a folder of its own and reads it back every way
     * there is.
     *
     * @return array<string, mixed>
     */
    public static function read(string $raw, string $host, int $port, string $user, string $password): array
    {
        // The same flags GreenmailTestCase::flags() honours, so pointing
        // the suite at a server with a real certificate does not fail
        // here alone.
        $flags = getenv('IMAP_POLYFILL_TEST_FLAGS') ?: '/imap/novalidate-cert';
        $spec = static fn (string $folder = '') => sprintf('{%s:%d%s}%s', $host, $port, $flags, $folder);
        $folder = 'Bodies'.bin2hex(random_bytes(4));

        $admin = imap_open($spec(), $user, $password);
        imap_createmailbox($admin, $spec($folder));
        imap_append($admin, $spec($folder), $raw);

        $connection = imap_open($spec($folder), $user, $password);
        imap_errors();

        $structure = imap_fetchstructure($connection, 1);

        return [
            'structure' => $structure instanceof \stdClass ? self::describeStructure($structure) : 'false',
            'body' => self::text(imap_body($connection, 1)),
            'section 1' => self::text(imap_fetchbody($connection, 1, '1')),
            'section 2' => self::text(imap_fetchbody($connection, 1, '2')),
            'mime of section 1' => self::text(imap_fetchmime($connection, 1, '1')),
            'bodystruct of section 1' => ($part = imap_bodystruct($connection, 1, '1')) instanceof \stdClass
                ? self::describeStructure($part)
                : 'false',
        ];
    }

    /**
     * The structure fields a caller reads to find the charset and the
     * encoding, flattened. Sizes are left out: a server is free to store a
     * message with the line endings it prefers, and two of them do.
     *
     * @return array<string, mixed>
     */
    private static function describeStructure(\stdClass $structure): array
    {
        $described = [];

        foreach (['type', 'encoding', 'ifsubtype', 'subtype', 'ifdisposition', 'disposition'] as $field) {
            if (property_exists($structure, $field)) {
                $described[$field] = $structure->$field;
            }
        }

        foreach (['parameters' => 'ifparameters', 'dparameters' => 'ifdparameters'] as $list => $flag) {
            $described[$flag] = $structure->$flag ?? 0;

            foreach ($structure->$list ?? [] as $parameter) {
                $described[$list][] = [$parameter->attribute, $parameter->value];
            }
        }

        foreach ($structure->parts ?? [] as $index => $part) {
            $described['parts'][$index] = self::describeStructure($part);
        }

        return $described;
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value)) {
            return $value === false ? 'false' : get_debug_type($value);
        }

        // Trailing whitespace is the server's business: one stores what it
        // was given, another adds the line ending it prefers.
        return rtrim($value, "\r\n");
    }

    private static function simple(string $contentType, string $encoding, string $body): string
    {
        return "Subject: Body\r\n"
            ."From: joe@example.com\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: {$contentType}\r\n"
            ."Content-Transfer-Encoding: {$encoding}\r\n"
            ."\r\n"
            .$body."\r\n";
    }
}
