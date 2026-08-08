<?php

namespace ImapPolyfill\Mailbox;

/**
 * ext-imap's mailbox specification, e.g. "{imap.example.com:993/ssl}INBOX":
 * the server to connect to, the switches governing how, and the folder to
 * select once connected. Used by imap_open() and imap_reopen().
 *
 * parse() frames the spec — host, switch run, folder — and hands the run to
 * SpecSwitches; between them they port c-client's mail_valid_net_parse_work()
 * (mail.c), down to which specs it refuses. A switch outside the closed set
 * does not open a connection with the switch ignored: it opens nothing.
 */
final class MailboxSpec
{
    /** NETMAXHOST: the host part, brackets included for a domain literal. */
    private const MAX_HOST = 256;

    /** NETMAXMBX: the folder part, everything after the closing brace. */
    private const MAX_FOLDER = 256;

    /** MAILTMPLEN: the port-and-switches run, between host and brace. */
    private const MAX_SWITCHES = 1024;

    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly Service $service,
        public readonly string $folder,
        public readonly SpecSwitches $switches,
    ) {
    }

    /**
     * How the connection is encrypted, as the two backends spell it: c-client's
     * /ssl is TLS from the first byte and /tls is a cleartext connection
     * upgraded with STARTTLS (IMAP) or STLS (POP3). Neither switch is not
     * "no TLS" — it is a cleartext connection that is still upgraded when the
     * server offers it, which is what Session does with this "" unless
     * /notls forbids it. Treating /tls as implicit TLS would open a TLS socket
     * against a plaintext listener, which simply never connects.
     */
    public function encryption(): string
    {
        return match (true) {
            $this->switches->ssl => 'ssl',
            $this->switches->tls => 'starttls',
            default => '',
        };
    }

    /**
     * The c-client-normalized "{host:port/service[/switches]" prefix that
     * imap4r1.c/pop3.c rebuild for stream->mailbox (surfaced by
     * imap_check()/imap_mailboxmsginfo()): always the port and the service
     * name, then the connection switches in c-client's fixed order. Stops
     * after "/secure" — the read-only marker and the /user="" suffix depend
     * on connection state, so IMAP\Connection appends them. The host stays
     * as given: c-client canonicalizes it via DNS, which this polyfill
     * deliberately doesn't (see README).
     *
     * $tls is what the connection negotiated rather than what the spec asked
     * for: c-client records the STARTTLS it did opportunistically, so a spec
     * with no TLS switch at all can still report /tls.
     */
    public function normalizedPrefixBase(bool $secure, bool $tls): string
    {
        $result = '{'.$this->host.':'.$this->port.'/'.$this->service->value;

        foreach ([
            'tls' => $tls,
            'tls-sslv23' => $this->switches->tlsSslv23,
            'notls' => $this->switches->notls,
            'ssl' => $this->switches->ssl,
            'novalidate-cert' => $this->switches->novalidate,
            'loser' => $this->switches->loser,
            'secure' => $secure,
        ] as $switch => $present) {
            if ($present) {
                $result .= '/'.$switch;
            }
        }

        return $result;
    }

    /**
     * @throws MalformedSpec when the string is not a spec c-client would open
     */
    public static function parse(string $mailbox): self
    {
        if (!str_starts_with($mailbox, '{')) {
            throw MalformedSpec::of($mailbox);
        }

        $rest = substr($mailbox, 1);
        $hostLength = self::hostLength($rest, $mailbox);
        $brace = strpos($rest, '}', $hostLength);

        if ($brace === false) {
            throw MalformedSpec::of($mailbox);
        }

        $run = substr($rest, $hostLength, $brace - $hostLength);
        $folder = substr($rest, $brace + 1);

        if ($hostLength >= self::MAX_HOST
            || strlen($run) >= self::MAX_SWITCHES
            || strlen($folder) >= self::MAX_FOLDER) {
            throw MalformedSpec::of($mailbox);
        }

        $switches = SpecSwitches::parse($run, $mailbox);
        $service = self::service($switches->service, $mailbox);

        // /norsh disables the rsh preauth, which only the IMAP driver has.
        if ($switches->norsh && $service !== Service::Imap) {
            throw MalformedSpec::of($mailbox);
        }

        return new self(
            substr($rest, 0, $hostLength),
            $switches->port ?? $service->defaultPort($switches->ssl),
            $service,
            // c-client treats an omitted folder part as INBOX: imap_open("{host}")
            // selects INBOX rather than leaving no mailbox selected.
            $folder !== '' ? $folder : 'INBOX',
            $switches,
        );
    }

    /**
     * Length of the host part, which is either a bracketed domain literal or
     * a run of anything up to the first "/", ":" or "}".
     *
     * @throws MalformedSpec
     */
    private static function hostLength(string $rest, string $mailbox): int
    {
        if (str_starts_with($rest, '[')) {
            $end = strcspn($rest, ']}');

            if ($end === strlen($rest) || $rest[$end] !== ']') {
                throw MalformedSpec::of($mailbox);
            }

            return $end + 1;
        }

        $length = strcspn($rest, '/:}');

        if ($length === 0 || $length === strlen($rest)) {
            throw MalformedSpec::of($mailbox);
        }

        return $length;
    }

    /**
     * The service switch resolved to the driver that will serve it. c-client
     * defaults to IMAP when the spec names none, and hands anything else to
     * mail_valid(), which finds no remote driver for it and reports the very
     * error a malformed spec gets — so /smtp, /submit and /service=anything
     * are refused here instead, indistinguishably to the caller.
     *
     * @throws MalformedSpec
     */
    private static function service(?string $service, string $mailbox): Service
    {
        if ($service === null) {
            return Service::Imap;
        }

        return Service::tryFrom($service) ?? throw MalformedSpec::of($mailbox);
    }
}
