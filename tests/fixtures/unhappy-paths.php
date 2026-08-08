<?php

/**
 * GENERATED FILE — do not edit by hand.
 *
 * What the genuine ext-imap returns, throws and records for each call in
 * tests/Support/UnhappyPaths.php made on a connection that cannot satisfy
 * it. Only cells both fixtures answer alike are kept, so what is here is
 * ext-imap's behaviour and not one server's.
 *
 * Left out, because the two servers disagree and the answer is
 * therefore theirs rather than ext-imap's:
 *
 *   - imap_setflag_full on a empty folder
 *   - imap_clearflag_full on a empty folder
 *   - imap_delete on a empty folder
 *   - imap_undelete on a empty folder
 *
 * generate-unhappy-paths.php is how to regenerate it; UnhappyPathsTest
 * asserts both engines against it.
 */

return [
    "empty folder" => [
        "imap_num_msg" => [
            "returns" => "int:0",
            "errors" => false,
        ],
        "imap_num_recent" => [
            "returns" => "int:0",
            "errors" => false,
        ],
        "imap_ping" => [
            "returns" => "true",
            "errors" => false,
        ],
        "imap_check" => [
            "returns" => "object:stdClass",
            "errors" => false,
        ],
        "imap_headers" => [
            "returns" => "array:0",
            "errors" => false,
        ],
        "imap_headerinfo" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_fetchheader" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_fetchbody" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_fetchmime" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_fetchstructure" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_body" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_fetch_overview" => [
            "returns" => "array:0",
            "errors" => true,
        ],
        "imap_search" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_sort" => [
            "returns" => "array:0",
            "errors" => false,
        ],
        "imap_uid" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_msgno" => [
            "returns" => "int:0",
            "errors" => false,
        ],
        "imap_expunge" => [
            "returns" => "true",
            "errors" => false,
        ],
        "imap_mailboxmsginfo" => [
            "returns" => "object:stdClass",
            "errors" => false,
        ],
        "imap_gc" => [
            "returns" => "true",
            "errors" => false,
        ],
        "imap_is_open" => [
            "returns" => "true",
            "errors" => false,
        ],
    ],
    "folder deleted underneath" => [
        "imap_num_msg" => [
            "returns" => "int:0",
            "errors" => false,
        ],
        "imap_num_recent" => [
            "returns" => "int:0",
            "errors" => false,
        ],
        "imap_ping" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_check" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_headers" => [
            "returns" => "array:0",
            "errors" => false,
        ],
        "imap_headerinfo" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_fetchheader" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_fetchbody" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_fetchmime" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_fetchstructure" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_body" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_fetch_overview" => [
            "returns" => "array:0",
            "errors" => true,
        ],
        "imap_search" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_sort" => [
            "returns" => "array:0",
            "errors" => true,
        ],
        "imap_uid" => [
            "returns" => "false",
            "errors" => false,
        ],
        "imap_msgno" => [
            "returns" => "int:0",
            "errors" => false,
        ],
        "imap_setflag_full" => [
            "returns" => "true",
            "errors" => true,
        ],
        "imap_clearflag_full" => [
            "returns" => "true",
            "errors" => true,
        ],
        "imap_delete" => [
            "returns" => "true",
            "errors" => true,
        ],
        "imap_undelete" => [
            "returns" => "true",
            "errors" => true,
        ],
        "imap_expunge" => [
            "returns" => "true",
            "errors" => true,
        ],
        "imap_mailboxmsginfo" => [
            "returns" => "object:stdClass",
            "errors" => false,
        ],
        "imap_gc" => [
            "returns" => "true",
            "errors" => false,
        ],
        "imap_is_open" => [
            "returns" => "true",
            "errors" => false,
        ],
    ],
    "closed connection" => [
        "imap_num_msg" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_num_recent" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_ping" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_check" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_headers" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_headerinfo" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_fetchheader" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_fetchbody" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_fetchmime" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_fetchstructure" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_body" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_fetch_overview" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_search" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_sort" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_uid" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_msgno" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_setflag_full" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_clearflag_full" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_delete" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_undelete" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_expunge" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_mailboxmsginfo" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_gc" => [
            "throws" => "ValueError",
            "message" => "IMAP\x5CConnection is already closed",
        ],
        "imap_is_open" => [
            "returns" => "false",
            "errors" => false,
        ],
    ],
];
