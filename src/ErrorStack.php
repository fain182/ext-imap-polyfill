<?php

namespace ImapPolyfill;

/**
 * ext-imap keeps a single global error/alert stack shared by all connections
 * (imap_errors/imap_last_error/imap_alerts take no connection argument).
 */
final class ErrorStack
{
    /** @var string[] */
    private static array $errors = [];

    /** @var string[] */
    private static array $alerts = [];

    private static string|false $lastError = false;

    public static function push(string $error): void
    {
        self::$errors[] = $error;
        self::$lastError = $error;
    }

    public static function pushAlert(string $alert): void
    {
        self::$alerts[] = $alert;
    }

    public static function last(): string|false
    {
        return self::$lastError;
    }

    /**
     * @return string[]|false
     */
    public static function drainErrors(): array|false
    {
        if (self::$errors === []) {
            return false;
        }

        $errors = self::$errors;
        self::$errors = [];

        return $errors;
    }

    /**
     * @return string[]|false
     */
    public static function drainAlerts(): array|false
    {
        if (self::$alerts === []) {
            return false;
        }

        $alerts = self::$alerts;
        self::$alerts = [];

        return $alerts;
    }
}
