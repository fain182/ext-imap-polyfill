<?php

namespace ImapPolyfill\Mailbox;

/**
 * The port and switches of a "{host:port/switch...}" spec — c-client's NETMBX
 * minus the host and mailbox, which MailboxSpec frames.
 *
 * The switch set is closed. parse() is a port of the scanning loop in
 * mail_valid_net_parse_work() (mail.c), including the combinations it
 * refuses: /tls after /notls is not the second one winning, or being ignored,
 * it is a spec c-client will not open.
 */
final class SpecSwitches
{
    /** NETMAXUSER: the argument of /user= and /authuser=. */
    private const MAX_USER = 65;

    /** NETMAXSRV: the argument of /service=. */
    private const MAX_SERVICE = 21;

    public function __construct(
        public readonly ?int $port = null,
        public readonly ?string $service = null,
        public readonly ?string $user = null,
        public readonly ?string $authuser = null,
        public readonly bool $anonymous = false,
        public readonly bool $debug = false,
        public readonly bool $readOnly = false,
        public readonly bool $secure = false,
        public readonly bool $norsh = false,
        public readonly bool $loser = false,
        public readonly bool $tls = false,
        public readonly bool $tlsSslv23 = false,
        public readonly bool $notls = false,
        public readonly bool $tryssl = false,
        public readonly bool $ssl = false,
        public readonly bool $novalidate = false,
    ) {
    }

    /**
     * Scans the run between the host and the closing brace, the way c-client
     * scans it: a leading delimiter, then a port or a switch, then whatever
     * delimiter ended it, until the run is used up. The two interleave freely
     * — "{host/ssl:993}" is as valid as "{host:993/ssl}".
     *
     * @param string $run     the delimiter-led run, "" when the spec has none
     * @param string $mailbox the whole spec, for the error message
     *
     * @throws MalformedSpec
     */
    public static function parse(string $run, string $mailbox): self
    {
        $length = strlen($run);
        $at = 0;

        $port = $service = $user = $authuser = null;
        $anonymous = $debug = $readOnly = $secure = $norsh = $loser = false;
        $tls = $tlsSslv23 = $notls = $tryssl = $ssl = $novalidate = false;

        if ($length === 0) {
            return new self();
        }

        $delimiter = $run[$at++];

        do {
            if ($delimiter === ':') {
                $digits = strspn($run, '0123456789', $at);
                $found = (int) substr($run, $at, $digits);

                if ($port !== null || $found === 0) {
                    throw MalformedSpec::of($mailbox);
                }

                $port = $found;
                $at += $digits;
                $delimiter = $at < $length ? $run[$at++] : '';

                continue;
            }

            if ($delimiter !== '/') {
                throw MalformedSpec::of($mailbox);
            }

            $nameLength = strcspn($run, '/:=', $at);
            $name = substr($run, $at, $nameLength);
            $at += $nameLength;
            $delimiter = $at < $length ? $run[$at++] : '';

            if ($delimiter === '=') {
                $argument = self::argument($run, $at, $mailbox);
                $delimiter = $at < $length ? $run[$at++] : '';

                // The first one given wins, and a second is not a correction
                // but a malformed spec.
                if (strcasecmp($name, 'service') === 0 && strlen($argument) < self::MAX_SERVICE && $service === null) {
                    $service = strtolower($argument);
                } elseif (strcasecmp($name, 'user') === 0 && strlen($argument) < self::MAX_USER && $user === null) {
                    $user = $argument;
                } elseif (strcasecmp($name, 'authuser') === 0 && strlen($argument) < self::MAX_USER && $authuser === null) {
                    $authuser = $argument;
                } else {
                    throw MalformedSpec::of($mailbox);
                }

                continue;
            }

            // Deliberately one chain, in c-client's order: a name that matches
            // a switch whose guard is false — /tls once /notls is set — does
            // not stop here, it falls through to the service names and out the
            // bottom as a malformed spec.
            if (strcasecmp($name, 'anonymous') === 0) {
                $anonymous = true;
            } elseif (strcasecmp($name, 'debug') === 0) {
                $debug = true;
            } elseif (strcasecmp($name, 'readonly') === 0) {
                $readOnly = true;
            } elseif (strcasecmp($name, 'secure') === 0) {
                $secure = true;
            } elseif (strcasecmp($name, 'norsh') === 0) {
                $norsh = true;
            } elseif (strcasecmp($name, 'loser') === 0) {
                $loser = true;
            } elseif (strcasecmp($name, 'tls') === 0 && !$notls) {
                $tls = true;
            } elseif (strcasecmp($name, 'tls-sslv23') === 0 && !$notls) {
                $tlsSslv23 = $tls = true;
            } elseif (strcasecmp($name, 'notls') === 0 && !$tls) {
                $notls = true;
            } elseif (strcasecmp($name, 'tryssl') === 0) {
                $tryssl = true;
            } elseif (strcasecmp($name, 'ssl') === 0 && !$tls) {
                // Implicit TLS from the first byte leaves no cleartext phase
                // to upgrade, so /ssl carries /notls with it — visible in the
                // Mailbox string c-client reports back.
                $ssl = $notls = true;
            } elseif (strcasecmp($name, 'novalidate-cert') === 0) {
                $novalidate = true;
            } elseif (strcasecmp($name, 'validate-cert') === 0) {
                // Accepted and inert: certificates are validated unless
                // /novalidate-cert says otherwise. c-client keeps the switch
                // for specs written when it meant something.
            } elseif ($service !== null) {
                throw MalformedSpec::of($mailbox);
            } elseif (in_array(strtolower($name), ['imap', 'nntp', 'pop3', 'smtp', 'submit'], true)) {
                $service = strtolower($name);
            } elseif (in_array(strtolower($name), ['imap2', 'imap2bis', 'imap4', 'imap4rev1'], true)) {
                $service = 'imap';
            } elseif (strcasecmp($name, 'pop') === 0) {
                $service = 'pop3';
            } else {
                throw MalformedSpec::of($mailbox);
            }
        } while ($delimiter !== '');

        return new self(
            $port, $service, $user, $authuser,
            $anonymous, $debug, $readOnly, $secure, $norsh, $loser,
            $tls, $tlsSslv23, $notls, $tryssl, $ssl, $novalidate,
        );
    }

    /**
     * The value of a "/name=value" switch: a quoted string, in which a
     * backslash quotes the next character, or a bare run up to the next
     * delimiter. $at is advanced past it.
     *
     * @throws MalformedSpec
     */
    private static function argument(string $run, int &$at, string $mailbox): string
    {
        $length = strlen($run);

        if (($at < $length ? $run[$at] : '') !== '"') {
            $valueLength = strcspn($run, '/:', $at);
            $value = substr($run, $at, $valueLength);
            $at += $valueLength;

            return $value;
        }

        $at++;
        $value = '';

        while (true) {
            if ($at >= $length) {
                throw MalformedSpec::of($mailbox);
            }

            $character = $run[$at++];

            if ($character === '"') {
                return $value;
            }

            if ($character === '\\') {
                if ($at >= $length) {
                    throw MalformedSpec::of($mailbox);
                }

                $character = $run[$at++];
            }

            $value .= $character;
        }
    }
}
