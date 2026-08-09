<?php

namespace ImapPolyfill\Support;

/**
 * ext-imap keeps these as process-global c-client parameters (mail_parameters),
 * not tied to a specific connection.
 *
 * Read and write are one value here, not two. A PHP socket has a single
 * timeout covering both directions, so keeping them apart would only mean
 * choosing between them later, by some rule nobody asked for: setting either
 * sets the timeout, and reading either reports it.
 */
final class Timeouts
{
    /** @var array<int, int>|null */
    private static ?array $values = null;

    /** The types that name the same shared value. */
    private const SHARED = [IMAP_READTIMEOUT, IMAP_WRITETIMEOUT];

    public static function get(int $type): int|false
    {
        self::init();

        return self::$values[$type] ?? false;
    }

    /**
     * The same value, as something a socket can be handed: a timeout of zero
     * or less would mean "never give up" to stream_set_timeout(), which is
     * the opposite of what asking for a timeout means.
     */
    public static function seconds(int $type): int
    {
        $value = self::get($type);

        return is_int($value) && $value > 0 ? $value : (int) ini_get('default_socket_timeout');
    }

    /** The one the socket is given, for however long an operation takes. */
    public static function socketSeconds(): int
    {
        return self::seconds(IMAP_READTIMEOUT);
    }

    public static function set(int $type, int $timeout): bool
    {
        self::init();

        if (!array_key_exists($type, self::$values)) {
            return false;
        }

        foreach (in_array($type, self::SHARED, true) ? self::SHARED : [$type] as $shared) {
            self::$values[$shared] = $timeout;
        }

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
            // c-client leaves the close timeout at zero: it has no
            // separate one, so it never reports default_socket_timeout here.
            IMAP_CLOSETIMEOUT => 0,
        ];
    }
}
