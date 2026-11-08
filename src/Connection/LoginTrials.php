<?php

namespace ImapPolyfill\Connection;

/**
 * c-client's SET_MAXLOGINTRIALS, which imap_open() and imap_reopen() set
 * from a nonzero $retries: how many times a refused login is sent again on
 * the same connection. It is a process-wide parameter, not a property of a
 * connection, so a later call that passes 0 inherits whatever was set last
 * (MAXLOGINTRIALS, 3, until then). mail_parameters() with no stream hands it
 * to every driver, which is why IMAP and POP3 share the one value.
 *
 * A connection that fails is never dialled again: the count is of logins.
 */
final class LoginTrials
{
    private static int $limit = 3;

    public static function limitTo(int $trials): void
    {
        self::$limit = $trials;
    }

    /**
     * Sends the login until the server accepts it or the trials run out,
     * the loop of imap_login() (imap4r1.c) and pop3_auth()'s USER/PASS
     * branch (pop3.c). Only a refusal is tried again; anything else, a
     * socket that is gone included, ends the loop where it happens.
     *
     * @param \Closure(): void $login
     *
     * @throws LoginRefused when every trial was refused
     */
    public static function attempt(\Closure $login): void
    {
        $refusals = [];

        while (true) {
            try {
                $login();

                return;
            } catch (CommandFailedException $e) {
                $refusals[] = $e->getMessage();
            }

            if (count($refusals) >= self::$limit) {
                throw new LoginRefused($refusals);
            }
        }
    }
}
