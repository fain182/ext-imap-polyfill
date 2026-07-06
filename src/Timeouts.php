<?php

namespace Fain182\ImapPolyfill;

/**
 * ext-imap keeps these as process-global c-client parameters (mail_parameters),
 * not tied to a specific connection.
 */
final class Timeouts
{
    private static ?array $values = null;

    public static function get(int $type): int|false
    {
        self::init();

        return self::$values[$type] ?? false;
    }

    public static function set(int $type, int $timeout): bool
    {
        self::init();

        if (!array_key_exists($type, self::$values)) {
            return false;
        }

        self::$values[$type] = $timeout;

        return true;
    }

    private static function init(): void
    {
        if (self::$values !== null) {
            return;
        }

        $default = (int) ini_get('default_socket_timeout');

        self::$values = [
            IMAP_OPENTIMEOUT => $default,
            IMAP_READTIMEOUT => $default,
            IMAP_WRITETIMEOUT => $default,
            IMAP_CLOSETIMEOUT => $default,
        ];
    }
}
