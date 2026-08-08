<?php

namespace ImapPolyfill\Connection;

/**
 * Who to log in as, and what the spec allows on the way there.
 *
 * The name is not always the one imap_open() was handed: php_imap.c's
 * mm_login() answers c-client with the spec's /user= when it has one, and
 * only falls back to the argument otherwise.
 *
 * The rest is what c-client checks before it will send a plaintext LOGIN.
 * It reaches that check only when no SASL authenticator it knows is
 * advertised — this package has none, so a spec asking for anything better
 * than LOGIN gets the refusal rather than the authentication.
 */
final class Credentials
{
    public function __construct(
        public readonly string $user,
        public readonly string $password,
        public readonly ?string $authuser = null,
        public readonly bool $anonymous = false,
        public readonly bool $secure = false,
    ) {
    }

    /**
     * The three refusals imap_login() (imap4r1.c) and pop3_auth() (pop3.c)
     * open with, in their order, before any credential goes out.
     *
     * @param list<string> $capabilities as CAPABILITY reported them
     *
     * @throws \RuntimeException carrying c-client's own message
     */
    public function assertPlaintextLoginAllowed(array $capabilities = []): void
    {
        if ($this->secure) {
            throw new \RuntimeException("Can't do secure authentication with this server");
        }

        if (in_array('LOGINDISABLED', $capabilities, true)) {
            throw new \RuntimeException('Server disables LOGIN, no recognized SASL authenticator');
        }

        // /authuser is an authentication identity distinct from the
        // authorization one, which only a SASL exchange can carry.
        if ($this->authuser !== null) {
            throw new \RuntimeException("Can't do /authuser with this server");
        }
    }
}
