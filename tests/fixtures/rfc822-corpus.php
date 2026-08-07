<?php

/**
 * GENERATED FILE — do not edit by hand.
 *
 * The answers the genuine ext-imap gives for the corpus in
 * generate-rfc822-corpus.php, which is also how to regenerate it.
 * Rfc822CorpusTest asserts both engines against it.
 */

return [
    "adrlist" => [
        "bare address" => [
            "input" => "joe@example.com",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                ],
            ],
        ],
        "angle address" => [
            "input" => "<joe@example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                ],
            ],
        ],
        "name and angle address" => [
            "input" => "Joe Doe <joe@example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                    "personal" => "Joe Doe",
                ],
            ],
        ],
        "name touching the bracket" => [
            "input" => "Joe<joe@example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                    "personal" => "Joe",
                ],
            ],
        ],
        "two addresses" => [
            "input" => "a@b.com, c@d.com",
            "expected" => [
                0 => [
                    "mailbox" => "a",
                    "host" => "b.com",
                ],
                1 => [
                    "mailbox" => "c",
                    "host" => "d.com",
                ],
            ],
        ],
        "no host at all" => [
            "input" => "joe",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "default.host",
                ],
            ],
        ],
        "comment after the name" => [
            "input" => "Joe (the boss) <joe@example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                    "personal" => "Joe",
                ],
            ],
        ],
        "nested comment" => [
            "input" => "Joe (the (big) boss) <joe@example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                    "personal" => "Joe",
                ],
            ],
        ],
        "comment before the name" => [
            "input" => "(hi) Joe <joe@example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                    "personal" => "Joe",
                ],
            ],
        ],
        "comment only" => [
            "input" => "(just a comment) joe@example.com",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                ],
            ],
        ],
        "comment between two addresses" => [
            "input" => "a@b.com, (hi) c@d.com",
            "expected" => [
                0 => [
                    "mailbox" => "a",
                    "host" => "b.com",
                ],
                1 => [
                    "mailbox" => "c",
                    "host" => "d.com",
                ],
            ],
        ],
        "comment after the address" => [
            "input" => "joe@example.com (Joe Doe)",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                    "personal" => "Joe Doe",
                ],
            ],
        ],
        "comment inside angle brackets" => [
            "input" => "<joe@(the host)example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                ],
            ],
        ],
        "unterminated comment" => [
            "input" => "Joe (never closed <joe@example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "Joe",
                    "host" => "default.host",
                ],
            ],
        ],
        "escaped paren inside a comment" => [
            "input" => "Joe (not \x5C) yet) <joe@example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                    "personal" => "Joe",
                ],
            ],
        ],
        "empty comment" => [
            "input" => "Joe () <joe@example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                    "personal" => "Joe",
                ],
            ],
        ],
        "quoted name" => [
            "input" => "\x22Simple Name\x22 <a@b.com>",
            "expected" => [
                0 => [
                    "mailbox" => "a",
                    "host" => "b.com",
                    "personal" => "Simple Name",
                ],
            ],
        ],
        "quoted name with a colon" => [
            "input" => "\x22This one: is right\x22 <ding@dong.com>",
            "expected" => [
                0 => [
                    "mailbox" => "ding",
                    "host" => "dong.com",
                    "personal" => "This one: is right",
                ],
            ],
        ],
        "quoted name with an escaped quote" => [
            "input" => "\x22This one: is \x5C\x22right\x5C\x22\x22 <ding@dong.com>",
            "expected" => [
                0 => [
                    "mailbox" => "ding",
                    "host" => "dong.com",
                    "personal" => "This one: is \x22right\x22",
                ],
            ],
        ],
        "quoted name with an escaped backslash" => [
            "input" => "\x22Back \x5C\x5C slash\x22 <a@b.com>",
            "expected" => [
                0 => [
                    "mailbox" => "a",
                    "host" => "b.com",
                    "personal" => "Back \x5C slash",
                ],
            ],
        ],
        "quoted name hiding a comma" => [
            "input" => "\x22Doe, John\x22 <a@b.com>",
            "expected" => [
                0 => [
                    "mailbox" => "a",
                    "host" => "b.com",
                    "personal" => "Doe, John",
                ],
            ],
        ],
        "quoted name hiding a comment" => [
            "input" => "\x22Joe (not a comment)\x22 <a@b.com>",
            "expected" => [
                0 => [
                    "mailbox" => "a",
                    "host" => "b.com",
                    "personal" => "Joe (not a comment)",
                ],
            ],
        ],
        "quoted name hiding an angle bracket" => [
            "input" => "\x22Joe <not@an.address>\x22 <a@b.com>",
            "expected" => [
                0 => [
                    "mailbox" => "a",
                    "host" => "b.com",
                    "personal" => "Joe <not@an.address>",
                ],
            ],
        ],
        "quoted name written empty" => [
            "input" => "\x22\x22 <a@b.com>",
            "expected" => [
                0 => [
                    "mailbox" => "a",
                    "host" => "b.com",
                    "personal" => "",
                ],
            ],
        ],
        "quote that never closes" => [
            "input" => "\x22unterminated <a@b.com>",
            "expected" => [
                0 => [
                    "mailbox" => "INVALID_ADDRESS",
                    "host" => ".SYNTAX-ERROR.",
                ],
            ],
        ],
        "quoted local part" => [
            "input" => "\x22joe doe\x22@example.com",
            "expected" => [
                0 => [
                    "mailbox" => "joe doe",
                    "host" => "example.com",
                ],
            ],
        ],
        "empty group" => [
            "input" => "undisclosed-recipients:;",
            "expected" => [
                0 => [
                    "mailbox" => "undisclosed-recipients",
                ],
                1 => [],
            ],
        ],
        "group with members" => [
            "input" => "Friends: alice@example.com, bob@example.com;",
            "expected" => [
                0 => [
                    "mailbox" => "Friends",
                ],
                1 => [
                    "mailbox" => "alice",
                    "host" => "example.com",
                ],
                2 => [
                    "mailbox" => "bob",
                    "host" => "example.com",
                ],
                3 => [],
            ],
        ],
        "group left unterminated" => [
            "input" => "Friends: alice@example.com",
            "expected" => [
                0 => [
                    "mailbox" => "Friends",
                ],
                1 => [
                    "mailbox" => "alice",
                    "host" => "example.com",
                ],
                2 => [],
            ],
        ],
        "address after a closed group" => [
            "input" => "Friends: a@b.com; c@d.com",
            "expected" => [
                0 => [
                    "mailbox" => "Friends",
                ],
                1 => [
                    "mailbox" => "a",
                    "host" => "b.com",
                ],
                2 => [],
                3 => [
                    "mailbox" => "UNEXPECTED_DATA_AFTER_ADDRESS",
                    "host" => ".SYNTAX-ERROR.",
                ],
            ],
        ],
        "route address" => [
            "input" => "@relay.example.com:user@example.com",
            "expected" => [
                0 => [
                    "mailbox" => "INVALID_ADDRESS",
                    "host" => ".SYNTAX-ERROR.",
                ],
            ],
        ],
        "folded before the address" => [
            "input" => "Joe\x0D\x0A <joe@example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                    "personal" => "Joe",
                ],
            ],
        ],
        "folded between addresses" => [
            "input" => "a@b.com,\x0D\x0A c@d.com",
            "expected" => [
                0 => [
                    "mailbox" => "a",
                    "host" => "b.com",
                ],
                1 => [
                    "mailbox" => "c",
                    "host" => "d.com",
                ],
            ],
        ],
        "encoded word as the name" => [
            "input" => "=?UTF-8?B?Sm9l?= <joe@example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                    "personal" => "=?UTF-8?B?Sm9l?=",
                ],
            ],
        ],
        "encoded word touching the bracket" => [
            "input" => "=?X-IAS-German?B?bXlHb3Y=?=<info@bla.bla>",
            "expected" => [
                0 => [
                    "mailbox" => "info",
                    "host" => "bla.bla",
                    "personal" => "=?X-IAS-German?B?bXlHb3Y=?=",
                ],
            ],
        ],
        "encoded word inside quotes" => [
            "input" => "\x22=?UTF-8?B?Sm9l?=\x22 <joe@example.com>",
            "expected" => [
                0 => [
                    "mailbox" => "joe",
                    "host" => "example.com",
                    "personal" => "=?UTF-8?B?Sm9l?=",
                ],
            ],
        ],
        "empty string" => [
            "input" => "",
            "expected" => [],
        ],
        "only whitespace" => [
            "input" => "   ",
            "expected" => [],
        ],
        "only a comma" => [
            "input" => ",",
            "expected" => [],
        ],
        "trailing comma" => [
            "input" => "a@b.com,",
            "expected" => [
                0 => [
                    "mailbox" => "a",
                    "host" => "b.com",
                ],
            ],
        ],
        "two at signs" => [
            "input" => "a@b@c.com",
            "expected" => [
                0 => [
                    "mailbox" => "a",
                    "host" => "b",
                ],
                1 => [
                    "mailbox" => "UNEXPECTED_DATA_AFTER_ADDRESS",
                    "host" => ".SYNTAX-ERROR.",
                ],
            ],
        ],
    ],
    "mime_header_decode" => [
        "plain text" => [
            "input" => "plain text",
            "expected" => [
                0 => [
                    0 => "default",
                    1 => "plain text",
                ],
            ],
        ],
        "one base64 word" => [
            "input" => "=?UTF-8?B?Y2lhbw==?=",
            "expected" => [
                0 => [
                    0 => "UTF-8",
                    1 => "ciao",
                ],
            ],
        ],
        "one quoted printable word" => [
            "input" => "=?ISO-8859-1?Q?caf=E9?=",
            "expected" => [
                0 => [
                    0 => "ISO-8859-1",
                    1 => "caf\xE9",
                ],
            ],
        ],
        "underscore is a space in Q" => [
            "input" => "=?ISO-8859-1?Q?Kilgore_Trout?=",
            "expected" => [
                0 => [
                    0 => "ISO-8859-1",
                    1 => "Kilgore Trout",
                ],
            ],
        ],
        "word between plain text" => [
            "input" => "a =?UTF-8?B?Yg==?= c",
            "expected" => [
                0 => [
                    0 => "default",
                    1 => "a ",
                ],
                1 => [
                    0 => "UTF-8",
                    1 => "b",
                ],
                2 => [
                    0 => "default",
                    1 => " c",
                ],
            ],
        ],
        "adjacent words, one space" => [
            "input" => "=?UTF-8?B?YQ==?= =?UTF-8?B?Yg==?=",
            "expected" => [
                0 => [
                    0 => "UTF-8",
                    1 => "a",
                ],
                1 => [
                    0 => "UTF-8",
                    1 => "b",
                ],
            ],
        ],
        "adjacent words, several spaces" => [
            "input" => "=?UTF-8?B?YQ==?=   =?UTF-8?B?Yg==?=",
            "expected" => [
                0 => [
                    0 => "UTF-8",
                    1 => "a",
                ],
                1 => [
                    0 => "UTF-8",
                    1 => "b",
                ],
            ],
        ],
        "adjacent words, a tab" => [
            "input" => "=?UTF-8?B?YQ==?=\x09=?UTF-8?B?Yg==?=",
            "expected" => [
                0 => [
                    0 => "UTF-8",
                    1 => "a",
                ],
                1 => [
                    0 => "UTF-8",
                    1 => "b",
                ],
            ],
        ],
        "adjacent words, folded" => [
            "input" => "=?UTF-8?B?YQ==?=\x0D\x0A =?UTF-8?B?Yg==?=",
            "expected" => [
                0 => [
                    0 => "UTF-8",
                    1 => "a",
                ],
                1 => [
                    0 => "UTF-8",
                    1 => "b",
                ],
            ],
        ],
        "adjacent words, nothing between" => [
            "input" => "=?UTF-8?B?YQ==?==?UTF-8?B?Yg==?=",
            "expected" => [
                0 => [
                    0 => "UTF-8",
                    1 => "a",
                ],
                1 => [
                    0 => "UTF-8",
                    1 => "b",
                ],
            ],
        ],
        "adjacent words, different charsets" => [
            "input" => "=?UTF-8?B?YQ==?= =?ISO-8859-1?Q?b?=",
            "expected" => [
                0 => [
                    0 => "UTF-8",
                    1 => "a",
                ],
                1 => [
                    0 => "ISO-8859-1",
                    1 => "b",
                ],
            ],
        ],
        "leading space" => [
            "input" => " =?UTF-8?B?YQ==?=",
            "expected" => [
                0 => [
                    0 => "default",
                    1 => " ",
                ],
                1 => [
                    0 => "UTF-8",
                    1 => "a",
                ],
            ],
        ],
        "trailing space" => [
            "input" => "=?UTF-8?B?YQ==?= ",
            "expected" => [
                0 => [
                    0 => "UTF-8",
                    1 => "a",
                ],
                1 => [
                    0 => "default",
                    1 => " ",
                ],
            ],
        ],
        "unknown encoding letter" => [
            "input" => "=?UTF-8?X?Y2lhbw==?=",
            "expected" => [
                0 => [
                    0 => "UTF-8",
                    1 => "Y2lhbw==",
                ],
            ],
        ],
        "undecodable base64" => [
            "input" => "=?UTF-8?B?bad!!base64?=",
            "expected" => false,
        ],
        "empty payload" => [
            "input" => "=?UTF-8?B??=",
            "expected" => [
                0 => [
                    0 => "UTF-8",
                    1 => "",
                ],
            ],
        ],
        "unterminated word" => [
            "input" => "=?UTF-8?B?Y2lhbw==",
            "expected" => [
                0 => [
                    0 => "default",
                    1 => "=?UTF-8?B?Y2lhbw==",
                ],
            ],
        ],
        "charset with a space" => [
            "input" => "=?UTF 8?B?Y2lhbw==?=",
            "expected" => [
                0 => [
                    0 => "UTF 8",
                    1 => "ciao",
                ],
            ],
        ],
    ],
];
