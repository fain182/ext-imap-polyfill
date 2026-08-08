<?php

/**
 * GENERATED FILE — do not edit by hand.
 *
 * What the genuine ext-imap answers for each call in
 * tests/Support/CharsetCalls.php. generate-charset-corpus.php is how to
 * regenerate it; CharsetCorpusTest asserts both engines against it.
 *
 * Byte values are escaped rather than written raw: several of these
 * answers are not valid UTF-8, and some are not meant to be.
 *
 * Left out, because the difference is the decomposed UTF-8 c-client
 * returns and matching it would mean carrying a Unicode decomposition
 * table; see the imap_utf8 row of the README's table:
 *
 *   - imap_utf8(UTF-8 word, accented latin)
 *   - imap_utf8(ISO-8859-1 word, accented latin)
 *   - imap_utf8(Q encoded, accented latin)
 *   - imap_utf8(UTF-8 word, mixed latin)
 *   - imap_utf8(ISO-8859-1 word, mixed latin)
 */

return [
    "imap_utf7_encode(ascii)" => [
        "returns" => "string",
        "value" => "plain",
    ],
    "imap_utf7_decode(encoded ascii)" => [
        "returns" => "string",
        "value" => "plain",
    ],
    "imap_utf7_encode(latin1 ascii)" => [
        "returns" => "string",
        "value" => "plain",
    ],
    "imap_utf8(UTF-8 word, ascii)" => [
        "returns" => "string",
        "value" => "plain",
    ],
    "imap_utf8(ISO-8859-1 word, ascii)" => [
        "returns" => "string",
        "value" => "plain",
    ],
    "imap_utf8(Windows-1251 word, ascii)" => [
        "returns" => "string",
        "value" => "plain",
    ],
    "imap_utf8(Q encoded, ascii)" => [
        "returns" => "string",
        "value" => "plain",
    ],
    "imap_utf8(raw 8-bit, ascii)" => [
        "returns" => "string",
        "value" => "plain",
    ],
    "imap_8bit(ascii)" => [
        "returns" => "string",
        "value" => "plain",
    ],
    "imap_qprint(8bit ascii)" => [
        "returns" => "string",
        "value" => "plain",
    ],
    "imap_binary(ascii)" => [
        "returns" => "string",
        "value" => "cGxhaW4=\x0D\x0A",
    ],
    "imap_base64(binary ascii)" => [
        "returns" => "string",
        "value" => "plain",
    ],
    "imap_utf7_encode(accented latin)" => [
        "returns" => "string",
        "value" => "caff&w6g-",
    ],
    "imap_utf7_decode(encoded accented latin)" => [
        "returns" => "string",
        "value" => "caff\xC3\xA8",
    ],
    "imap_utf7_encode(latin1 accented latin)" => [
        "returns" => "string",
        "value" => "caff&6A-",
    ],
    "imap_utf8(Windows-1251 word, accented latin)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf8(raw 8-bit, accented latin)" => [
        "returns" => "string",
        "value" => "caff\xC3\xA8",
    ],
    "imap_8bit(accented latin)" => [
        "returns" => "string",
        "value" => "caff=C3=A8",
    ],
    "imap_qprint(8bit accented latin)" => [
        "returns" => "string",
        "value" => "caff\xC3\xA8",
    ],
    "imap_binary(accented latin)" => [
        "returns" => "string",
        "value" => "Y2FmZsOo\x0D\x0A",
    ],
    "imap_base64(binary accented latin)" => [
        "returns" => "string",
        "value" => "caff\xC3\xA8",
    ],
    "imap_utf7_encode(mixed latin)" => [
        "returns" => "string",
        "value" => "Zo&w6s- Doe",
    ],
    "imap_utf7_decode(encoded mixed latin)" => [
        "returns" => "string",
        "value" => "Zo\xC3\xAB Doe",
    ],
    "imap_utf7_encode(latin1 mixed latin)" => [
        "returns" => "string",
        "value" => "Zo&6w- Doe",
    ],
    "imap_utf8(Windows-1251 word, mixed latin)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf8(Q encoded, mixed latin)" => [
        "returns" => "string",
        "value" => "=?UTF-8?Q?Zo=C3=AB Doe?=",
    ],
    "imap_utf8(raw 8-bit, mixed latin)" => [
        "returns" => "string",
        "value" => "Zo\xC3\xAB Doe",
    ],
    "imap_8bit(mixed latin)" => [
        "returns" => "string",
        "value" => "Zo=C3=AB Doe",
    ],
    "imap_qprint(8bit mixed latin)" => [
        "returns" => "string",
        "value" => "Zo\xC3\xAB Doe",
    ],
    "imap_binary(mixed latin)" => [
        "returns" => "string",
        "value" => "Wm/DqyBEb2U=\x0D\x0A",
    ],
    "imap_base64(binary mixed latin)" => [
        "returns" => "string",
        "value" => "Zo\xC3\xAB Doe",
    ],
    "imap_utf7_encode(cyrillic)" => [
        "returns" => "string",
        "value" => "&0J,RgNC40LLQtdGC-",
    ],
    "imap_utf7_decode(encoded cyrillic)" => [
        "returns" => "string",
        "value" => "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82",
    ],
    "imap_utf7_encode(latin1 cyrillic)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf8(UTF-8 word, cyrillic)" => [
        "returns" => "string",
        "value" => "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82",
    ],
    "imap_utf8(ISO-8859-1 word, cyrillic)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf8(Windows-1251 word, cyrillic)" => [
        "returns" => "string",
        "value" => "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82",
    ],
    "imap_utf8(Q encoded, cyrillic)" => [
        "returns" => "string",
        "value" => "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82",
    ],
    "imap_utf8(raw 8-bit, cyrillic)" => [
        "returns" => "string",
        "value" => "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82",
    ],
    "imap_8bit(cyrillic)" => [
        "returns" => "string",
        "value" => "=D0=9F=D1=80=D0=B8=D0=B2=D0=B5=D1=82",
    ],
    "imap_qprint(8bit cyrillic)" => [
        "returns" => "string",
        "value" => "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82",
    ],
    "imap_binary(cyrillic)" => [
        "returns" => "string",
        "value" => "0J/RgNC40LLQtdGC\x0D\x0A",
    ],
    "imap_base64(binary cyrillic)" => [
        "returns" => "string",
        "value" => "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82",
    ],
    "imap_utf7_encode(cjk)" => [
        "returns" => "string",
        "value" => "&5L2g5aW9-",
    ],
    "imap_utf7_decode(encoded cjk)" => [
        "returns" => "string",
        "value" => "\xE4\xBD\xA0\xE5\xA5\xBD",
    ],
    "imap_utf7_encode(latin1 cjk)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf8(UTF-8 word, cjk)" => [
        "returns" => "string",
        "value" => "\xE4\xBD\xA0\xE5\xA5\xBD",
    ],
    "imap_utf8(ISO-8859-1 word, cjk)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf8(Windows-1251 word, cjk)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf8(Q encoded, cjk)" => [
        "returns" => "string",
        "value" => "\xE4\xBD\xA0\xE5\xA5\xBD",
    ],
    "imap_utf8(raw 8-bit, cjk)" => [
        "returns" => "string",
        "value" => "\xE4\xBD\xA0\xE5\xA5\xBD",
    ],
    "imap_8bit(cjk)" => [
        "returns" => "string",
        "value" => "=E4=BD=A0=E5=A5=BD",
    ],
    "imap_qprint(8bit cjk)" => [
        "returns" => "string",
        "value" => "\xE4\xBD\xA0\xE5\xA5\xBD",
    ],
    "imap_binary(cjk)" => [
        "returns" => "string",
        "value" => "5L2g5aW9\x0D\x0A",
    ],
    "imap_base64(binary cjk)" => [
        "returns" => "string",
        "value" => "\xE4\xBD\xA0\xE5\xA5\xBD",
    ],
    "imap_utf7_encode(symbol)" => [
        "returns" => "string",
        "value" => "&4oKs-uro",
    ],
    "imap_utf7_decode(encoded symbol)" => [
        "returns" => "string",
        "value" => "\xE2\x82\xACuro",
    ],
    "imap_utf7_encode(latin1 symbol)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf8(UTF-8 word, symbol)" => [
        "returns" => "string",
        "value" => "\xE2\x82\xACuro",
    ],
    "imap_utf8(ISO-8859-1 word, symbol)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf8(Windows-1251 word, symbol)" => [
        "returns" => "string",
        "value" => "\xE2\x82\xACuro",
    ],
    "imap_utf8(Q encoded, symbol)" => [
        "returns" => "string",
        "value" => "\xE2\x82\xACuro",
    ],
    "imap_utf8(raw 8-bit, symbol)" => [
        "returns" => "string",
        "value" => "\xE2\x82\xACuro",
    ],
    "imap_8bit(symbol)" => [
        "returns" => "string",
        "value" => "=E2=82=ACuro",
    ],
    "imap_qprint(8bit symbol)" => [
        "returns" => "string",
        "value" => "\xE2\x82\xACuro",
    ],
    "imap_binary(symbol)" => [
        "returns" => "string",
        "value" => "4oKsdXJv\x0D\x0A",
    ],
    "imap_base64(binary symbol)" => [
        "returns" => "string",
        "value" => "\xE2\x82\xACuro",
    ],
    "imap_utf7_encode(combining already)" => [
        "returns" => "string",
        "value" => "cafe&zIE-",
    ],
    "imap_utf7_decode(encoded combining already)" => [
        "returns" => "string",
        "value" => "cafe\xCC\x81",
    ],
    "imap_utf7_encode(latin1 combining already)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf8(UTF-8 word, combining already)" => [
        "returns" => "string",
        "value" => "cafe\xCC\x81",
    ],
    "imap_utf8(ISO-8859-1 word, combining already)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf8(Windows-1251 word, combining already)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf8(Q encoded, combining already)" => [
        "returns" => "string",
        "value" => "cafe\xCC\x81",
    ],
    "imap_utf8(raw 8-bit, combining already)" => [
        "returns" => "string",
        "value" => "cafe\xCC\x81",
    ],
    "imap_8bit(combining already)" => [
        "returns" => "string",
        "value" => "cafe=CC=81",
    ],
    "imap_qprint(8bit combining already)" => [
        "returns" => "string",
        "value" => "cafe\xCC\x81",
    ],
    "imap_binary(combining already)" => [
        "returns" => "string",
        "value" => "Y2FmZcyB\x0D\x0A",
    ],
    "imap_base64(binary combining already)" => [
        "returns" => "string",
        "value" => "cafe\xCC\x81",
    ],
    "imap_utf8(charset nobody has)" => [
        "returns" => "string",
        "value" => "myGov",
    ],
    "imap_utf8(unterminated word)" => [
        "returns" => "string",
        "value" => "=?UTF-8?B?Y2FmZQ==",
    ],
    "imap_utf8(empty payload)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_utf7_decode(not utf7)" => [
        "returns" => "bool",
        "value" => false,
    ],
    "imap_utf7_decode(bare ampersand)" => [
        "returns" => "bool",
        "value" => false,
    ],
    "imap_utf7_decode(escaped ampersand)" => [
        "returns" => "string",
        "value" => "a&b",
    ],
    "imap_utf7_encode(empty)" => [
        "returns" => "string",
        "value" => "",
    ],
    "imap_base64(not base64)" => [
        "returns" => "bool",
        "value" => false,
    ],
    "imap_qprint(broken escape)" => [
        "returns" => "string",
        "value" => "caf=ZZ",
    ],
];
