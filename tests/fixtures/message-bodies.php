<?php

/**
 * GENERATED FILE — do not edit by hand.
 *
 * What the genuine ext-imap reports for each message in
 * tests/Support/MessageBodies.php: the structure a caller reads the
 * charset from, and the bytes the fetches hand back.
 *
 * Left out, because the two servers disagree and the answer is
 * therefore theirs rather than ext-imap's:
 *
 *   - mime of section 1 for utf-8 8bit
 *   - mime of section 1 for iso-8859-1 quoted-printable
 *   - mime of section 1 for windows-1251 base64
 *   - structure for no charset declared
 *   - mime of section 1 for no charset declared
 *   - bodystruct of section 1 for no charset declared
 *   - section 1 for charset nobody has
 *   - mime of section 1 for charset nobody has
 *   - mime of section 1 for quoted charset
 *   - structure for attachment named across continuations
 *
 * generate-message-bodies.php is how to regenerate it; MessageBodiesTest
 * asserts both engines against it.
 */

return [
    "utf-8 8bit" => [
        "structure" => [
            "type" => 0,
            "encoding" => 1,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "UTF-8",
                ],
            ],
            "ifdparameters" => 0,
        ],
        "body" => "caff\xC3\xA8 \xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82 \xE4\xBD\xA0\xE5\xA5\xBD",
        "section 1" => "caff\xC3\xA8 \xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82 \xE4\xBD\xA0\xE5\xA5\xBD",
        "section 2" => "",
        "bodystruct of section 1" => [
            "type" => 0,
            "encoding" => 1,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "UTF-8",
                ],
            ],
            "ifdparameters" => 0,
        ],
    ],
    "iso-8859-1 quoted-printable" => [
        "structure" => [
            "type" => 0,
            "encoding" => 4,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "ISO-8859-1",
                ],
            ],
            "ifdparameters" => 0,
        ],
        "body" => "caff=E8",
        "section 1" => "caff=E8",
        "section 2" => "",
        "bodystruct of section 1" => [
            "type" => 0,
            "encoding" => 4,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "ISO-8859-1",
                ],
            ],
            "ifdparameters" => 0,
        ],
    ],
    "windows-1251 base64" => [
        "structure" => [
            "type" => 0,
            "encoding" => 3,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "Windows-1251",
                ],
            ],
            "ifdparameters" => 0,
        ],
        "body" => "z/Do4uXy",
        "section 1" => "z/Do4uXy",
        "section 2" => "",
        "bodystruct of section 1" => [
            "type" => 0,
            "encoding" => 3,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "Windows-1251",
                ],
            ],
            "ifdparameters" => 0,
        ],
    ],
    "no charset declared" => [
        "body" => "caff\xC3\xA8 \xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82 \xE4\xBD\xA0\xE5\xA5\xBD",
        "section 1" => "caff\xC3\xA8 \xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82 \xE4\xBD\xA0\xE5\xA5\xBD",
        "section 2" => "",
    ],
    "charset nobody has" => [
        "structure" => [
            "type" => 0,
            "encoding" => 1,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "X-IAS-German",
                ],
            ],
            "ifdparameters" => 0,
        ],
        "body" => "caff\xC3\xA8 \xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82 \xE4\xBD\xA0\xE5\xA5\xBD",
        "section 2" => "",
        "bodystruct of section 1" => [
            "type" => 0,
            "encoding" => 1,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "X-IAS-German",
                ],
            ],
            "ifdparameters" => 0,
        ],
    ],
    "quoted charset" => [
        "structure" => [
            "type" => 0,
            "encoding" => 1,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "UTF-8",
                ],
            ],
            "ifdparameters" => 0,
        ],
        "body" => "caff\xC3\xA8 \xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82 \xE4\xBD\xA0\xE5\xA5\xBD",
        "section 1" => "caff\xC3\xA8 \xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82 \xE4\xBD\xA0\xE5\xA5\xBD",
        "section 2" => "",
        "bodystruct of section 1" => [
            "type" => 0,
            "encoding" => 1,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "UTF-8",
                ],
            ],
            "ifdparameters" => 0,
        ],
    ],
    "multipart, two charsets" => [
        "structure" => [
            "type" => 1,
            "encoding" => 0,
            "ifsubtype" => 1,
            "subtype" => "ALTERNATIVE",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "boundary",
                    1 => "sep",
                ],
            ],
            "ifdparameters" => 0,
            "parts" => [
                0 => [
                    "type" => 0,
                    "encoding" => 1,
                    "ifsubtype" => 1,
                    "subtype" => "PLAIN",
                    "ifdisposition" => 0,
                    "ifparameters" => 1,
                    "parameters" => [
                        0 => [
                            0 => "charset",
                            1 => "UTF-8",
                        ],
                    ],
                    "ifdparameters" => 0,
                ],
                1 => [
                    "type" => 0,
                    "encoding" => 4,
                    "ifsubtype" => 1,
                    "subtype" => "HTML",
                    "ifdisposition" => 0,
                    "ifparameters" => 1,
                    "parameters" => [
                        0 => [
                            0 => "charset",
                            1 => "ISO-8859-1",
                        ],
                    ],
                    "ifdparameters" => 0,
                ],
            ],
        ],
        "body" => "--sep\x0D\x0AContent-Type: text/plain; charset=UTF-8\x0D\x0AContent-Transfer-Encoding: 8bit\x0D\x0A\x0D\x0Acaff\xC3\xA8 \xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82 \xE4\xBD\xA0\xE5\xA5\xBD\x0D\x0A--sep\x0D\x0AContent-Type: text/html; charset=ISO-8859-1\x0D\x0AContent-Transfer-Encoding: quoted-printable\x0D\x0A\x0D\x0A<p>caff=E8</p>\x0D\x0A--sep--",
        "section 1" => "caff\xC3\xA8 \xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82 \xE4\xBD\xA0\xE5\xA5\xBD",
        "section 2" => "<p>caff=E8</p>",
        "mime of section 1" => "Content-Type: text/plain; charset=UTF-8\x0D\x0AContent-Transfer-Encoding: 8bit",
        "bodystruct of section 1" => [
            "type" => 0,
            "encoding" => 1,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "UTF-8",
                ],
            ],
            "ifdparameters" => 0,
        ],
    ],
    "attachment named with an encoded word" => [
        "structure" => [
            "type" => 1,
            "encoding" => 0,
            "ifsubtype" => 1,
            "subtype" => "MIXED",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "boundary",
                    1 => "sep",
                ],
            ],
            "ifdparameters" => 0,
            "parts" => [
                0 => [
                    "type" => 0,
                    "encoding" => 0,
                    "ifsubtype" => 1,
                    "subtype" => "PLAIN",
                    "ifdisposition" => 0,
                    "ifparameters" => 1,
                    "parameters" => [
                        0 => [
                            0 => "charset",
                            1 => "UTF-8",
                        ],
                    ],
                    "ifdparameters" => 0,
                ],
                1 => [
                    "type" => 3,
                    "encoding" => 3,
                    "ifsubtype" => 1,
                    "subtype" => "OCTET-STREAM",
                    "ifdisposition" => 1,
                    "disposition" => "attachment",
                    "ifparameters" => 1,
                    "parameters" => [
                        0 => [
                            0 => "name",
                            1 => "=?UTF-8?B?cmVsYXppb25lIGZpbmFuemlhcmlhLnBkZg==?=",
                        ],
                    ],
                    "ifdparameters" => 1,
                    "dparameters" => [
                        0 => [
                            0 => "filename",
                            1 => "=?UTF-8?B?cmVsYXppb25lIGZpbmFuemlhcmlhLnBkZg==?=",
                        ],
                    ],
                ],
            ],
        ],
        "body" => "--sep\x0D\x0AContent-Type: text/plain; charset=UTF-8\x0D\x0A\x0D\x0Asee attached\x0D\x0A--sep\x0D\x0AContent-Type: application/octet-stream; name=\x22=?UTF-8?B?cmVsYXppb25lIGZpbmFuemlhcmlhLnBkZg==?=\x22\x0D\x0AContent-Disposition: attachment; filename=\x22=?UTF-8?B?cmVsYXppb25lIGZpbmFuemlhcmlhLnBkZg==?=\x22\x0D\x0AContent-Transfer-Encoding: base64\x0D\x0A\x0D\x0Abm90IHJlYWxseSBhIHBkZg==\x0D\x0A--sep--",
        "section 1" => "see attached",
        "section 2" => "bm90IHJlYWxseSBhIHBkZg==",
        "mime of section 1" => "Content-Type: text/plain; charset=UTF-8",
        "bodystruct of section 1" => [
            "type" => 0,
            "encoding" => 0,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "UTF-8",
                ],
            ],
            "ifdparameters" => 0,
        ],
    ],
    "attachment named across continuations" => [
        "body" => "--sep\x0D\x0AContent-Type: text/plain; charset=UTF-8\x0D\x0A\x0D\x0Asee attached\x0D\x0A--sep\x0D\x0AContent-Type: application/octet-stream;\x0D\x0A name*0*=UTF-8''relazione%20;\x0D\x0A name*1*=finanziaria.pdf\x0D\x0AContent-Transfer-Encoding: base64\x0D\x0A\x0D\x0Abm90IHJlYWxseSBhIHBkZg==\x0D\x0A--sep--",
        "section 1" => "see attached",
        "section 2" => "bm90IHJlYWxseSBhIHBkZg==",
        "mime of section 1" => "Content-Type: text/plain; charset=UTF-8",
        "bodystruct of section 1" => [
            "type" => 0,
            "encoding" => 0,
            "ifsubtype" => 1,
            "subtype" => "PLAIN",
            "ifdisposition" => 0,
            "ifparameters" => 1,
            "parameters" => [
                0 => [
                    0 => "charset",
                    1 => "UTF-8",
                ],
            ],
            "ifdparameters" => 0,
        ],
    ],
];
