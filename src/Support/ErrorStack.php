<?php

namespace ImapPolyfill\Support;

/**
 * ext-imap keeps a single global error/alert stack shared by all connections
 * (imap_errors/imap_last_error/imap_alerts take no connection argument).
 *
 * php_imap.c resets this stack in PHP_RINIT_FUNCTION, so the real extension
 * never leaks errors from one request into the next even under threaded/
 * persistent SAPIs. A plain PHP `static` only mimics that by accident on
 * shared-nothing SAPIs (FPM/CGI tear down userland statics between
 * requests); on worker-mode runtimes (Swoole, RoadRunner, FrankenPHP worker
 * mode) it wouldn't. Self-registering a shutdown function reproduces the
 * RINIT reset regardless of SAPI, since shutdown functions are request-
 * scoped even when class statics aren't.
 */
final class ErrorStack
{
    /** @var string[] */
    private static array $errors = [];

    /** @var string[] */
    private static array $alerts = [];

    private static string|false $lastError = false;

    private static bool $shutdownRegistered = false;

    public static function push(string $error): void
    {
        self::registerShutdownReset();
        self::$errors[] = $error;
        self::$lastError = $error;
    }

    public static function pushAlert(string $alert): void
    {
        self::registerShutdownReset();
        self::$alerts[] = $alert;
    }

    private static function registerShutdownReset(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function(self::resetForNextRequest(...));
    }

    private static function resetForNextRequest(): void
    {
        self::$errors = [];
        self::$alerts = [];
        self::$lastError = false;
        self::$shutdownRegistered = false;
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
        // ext-imap's imap_last_error() reads the same stack imap_errors()
        // frees, so draining the stack clears the last error too.
        self::$lastError = false;

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
