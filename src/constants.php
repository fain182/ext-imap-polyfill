<?php

if (!defined('NIL')) {
    define('NIL', 0);
}

if (!defined('OP_DEBUG')) {
    define('OP_DEBUG', 0x1);
}

if (!defined('OP_READONLY')) {
    define('OP_READONLY', 0x2);
}

if (!defined('OP_ANONYMOUS')) {
    define('OP_ANONYMOUS', 0x4);
}

if (!defined('OP_SHORTCACHE')) {
    define('OP_SHORTCACHE', 0x8);
}

if (!defined('OP_SILENT')) {
    define('OP_SILENT', 0x10);
}

if (!defined('OP_PROTOTYPE')) {
    define('OP_PROTOTYPE', 0x20);
}

if (!defined('OP_HALFOPEN')) {
    define('OP_HALFOPEN', 0x40);
}

if (!defined('OP_EXPUNGE')) {
    define('OP_EXPUNGE', 0x80);
}

if (!defined('OP_SECURE')) {
    define('OP_SECURE', 0x100);
}

if (!defined('CL_EXPUNGE')) {
    // This is PHP's own PHP_EXPUNGE define (php_imap.c), not c-client's
    // CL_EXPUNGE (0x1) — ext-imap deliberately exposes a different userland
    // value so it can't collide with the OP_* bitmask passed to imap_open(),
    // then translates it to c-client's real CL_EXPUNGE internally.
    define('CL_EXPUNGE', 32768);
}

if (!defined('IMAP_OPENTIMEOUT')) {
    define('IMAP_OPENTIMEOUT', 1);
}

if (!defined('IMAP_READTIMEOUT')) {
    define('IMAP_READTIMEOUT', 2);
}

if (!defined('IMAP_WRITETIMEOUT')) {
    define('IMAP_WRITETIMEOUT', 3);
}

if (!defined('IMAP_CLOSETIMEOUT')) {
    define('IMAP_CLOSETIMEOUT', 4);
}

if (!defined('SE_UID')) {
    define('SE_UID', 0x1);
}

if (!defined('SE_FREE')) {
    define('SE_FREE', 0x2);
}

if (!defined('FT_UID')) {
    define('FT_UID', 0x1);
}

if (!defined('FT_PEEK')) {
    define('FT_PEEK', 0x2);
}

if (!defined('LATT_NOINFERIORS')) {
    define('LATT_NOINFERIORS', 0x1);
}

if (!defined('LATT_NOSELECT')) {
    define('LATT_NOSELECT', 0x2);
}

if (!defined('LATT_MARKED')) {
    define('LATT_MARKED', 0x4);
}

if (!defined('LATT_UNMARKED')) {
    define('LATT_UNMARKED', 0x8);
}

if (!defined('LATT_REFERRAL')) {
    define('LATT_REFERRAL', 0x10);
}

if (!defined('LATT_HASCHILDREN')) {
    define('LATT_HASCHILDREN', 0x20);
}

if (!defined('LATT_HASNOCHILDREN')) {
    define('LATT_HASNOCHILDREN', 0x40);
}

if (!defined('ST_UID')) {
    define('ST_UID', 0x1);
}

if (!defined('FT_INTERNAL')) {
    define('FT_INTERNAL', 0x8);
}

if (!defined('CP_UID')) {
    define('CP_UID', 0x1);
}

if (!defined('CP_MOVE')) {
    define('CP_MOVE', 0x2);
}

if (!defined('SA_MESSAGES')) {
    define('SA_MESSAGES', 0x1);
}

if (!defined('SA_RECENT')) {
    define('SA_RECENT', 0x2);
}

if (!defined('SA_UNSEEN')) {
    define('SA_UNSEEN', 0x4);
}

if (!defined('SA_UIDNEXT')) {
    define('SA_UIDNEXT', 0x8);
}

if (!defined('SA_UIDVALIDITY')) {
    define('SA_UIDVALIDITY', 0x10);
}

if (!defined('SA_ALL')) {
    define('SA_ALL', SA_MESSAGES | SA_RECENT | SA_UNSEEN | SA_UIDNEXT | SA_UIDVALIDITY);
}
