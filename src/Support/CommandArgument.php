<?php

namespace ImapPolyfill\Support;

/**
 * An argument written into a command line ends it at the first CR or LF it
 * holds, and whatever follows is read as a second command — in a session
 * already logged in. IMAP and POP3 refuse it in the same words, so the
 * words live here.
 */
final class CommandArgument
{
    /** NUL travels no better than a line break; both end what holds them. */
    private const FORBIDDEN = "\r\n\0";

    public static function assertOneCommand(string $argument): void
    {
        if (strpbrk($argument, self::FORBIDDEN) !== false) {
            throw new \RuntimeException('Command argument contains a line break');
        }
    }
}
