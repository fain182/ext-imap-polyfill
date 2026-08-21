<?php

namespace ImapPolyfill\Support;

/**
 * The one rule both protocols enforce on an argument of their own making:
 * it has to stay inside the command it belongs to.
 *
 * An argument written into a command line as it stands ends that line at
 * the first CR or LF it holds, and whatever follows is read as a second
 * command — in a session that has already logged in. IMAP and POP3 refuse
 * it in the same words, which is why the words live here rather than in
 * each of them.
 */
final class CommandArgument
{
    /** NUL travels no better than a line break; both end what holds them. */
    private const FORBIDDEN = "\r\n\0";

    /**
     * @throws \RuntimeException as the wrappers expect, so the caller sees
     *                           the usual return value and stack message
     */
    public static function assertOneCommand(string $argument): void
    {
        if (strpbrk($argument, self::FORBIDDEN) !== false) {
            throw new \RuntimeException('Command argument contains a line break');
        }
    }
}
