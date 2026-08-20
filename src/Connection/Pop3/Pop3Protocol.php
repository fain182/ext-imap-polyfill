<?php

namespace ImapPolyfill\Connection\Pop3;

/**
 * Minimal RFC1939 POP3 client over a raw socket: just the commands this
 * polyfill's ConnectionBackend needs (USER/PASS, STAT, RETR, TOP, DELE,
 * RSET, NOOP, QUIT). No APOP/AUTH — Greenmail and the real ext-imap
 * parity target both accept plaintext USER/PASS.
 */
final class Pop3Protocol
{
    /** The ceiling on a line of the server's own talk; see readStatusLine(). */
    private const MAX_STATUS_LINE = 8192;

    /** @var resource */
    private $stream;

    private bool $upgraded = false;

    public function connect(
        string $host,
        int $port,
        string $encryption,
        bool $notls,
        bool $validateCert,
        float $timeout = 30.0,
        ?float $readTimeout = null,
    ): void {
        // /ssl is TLS from the first byte; /tls starts in the clear and
        // upgrades with STLS below. Both must end up encrypted: c-client
        // refuses to continue when the upgrade fails, and connecting in
        // cleartext because the server can't do STLS would hand the caller
        // the opposite of what the flag asked for.
        $scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $validateCert,
                'verify_peer_name' => $validateCert,
            ],
        ]);

        $stream = @stream_socket_client(
            "{$scheme}{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            // Same "host,port" shape as c-client's tcp_open error.
            throw new \RuntimeException("Can't connect to {$host},{$port}: {$errstr}");
        }

        $this->stream = $stream;
        stream_set_timeout($this->stream, (int) ($readTimeout ?? $timeout));

        $this->readSingleLine();

        if ($encryption !== 'ssl') {
            $this->upgrade($encryption === 'starttls', $notls);
        }
    }

    /**
     * c-client upgrades whenever it can (pop3.c): STLS goes out on any
     * connection not already encrypted and not forbidden by /notls, and a
     * spec that asked for /tls and found no server to negotiate with fails
     * rather than continuing in the clear.
     */
    private function upgrade(bool $required, bool $forbidden): void
    {
        if ($forbidden) {
            return;
        }

        if (in_array('STLS', $this->capa(), true)) {
            $this->startTls();

            return;
        }

        if ($required) {
            throw new \RuntimeException('Unable to negotiate TLS with this server');
        }
    }

    /**
     * RFC 2595 STLS. Throws rather than carrying on unencrypted: an upgrade
     * that failed halfway is the one outcome the switch exists to prevent.
     *
     * The method is the IMAP side's (ImapEngineConnection::startTls), so
     * that a spec gets the same TLS versions whichever protocol it names.
     * STREAM_CRYPTO_METHOD_ANY_CLIENT is that set plus SSLv2 and SSLv3,
     * which no server this package can reach still speaks and no client
     * should offer.
     */
    private function startTls(): void
    {
        $this->command('STLS');

        $crypto = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if ($crypto !== true) {
            throw new \RuntimeException('Unable to negotiate TLS with this server');
        }

        $this->upgraded = true;
    }

    /**
     * Whether this connection reached TLS through STLS rather than having
     * been encrypted from its first byte — what the reported Mailbox string
     * spells "/tls".
     */
    public function upgradedToTls(): bool
    {
        return $this->upgraded;
    }

    /**
     * RFC 2449 CAPA, read for STLS alone.
     *
     * A -ERR is a complete answer, not a failure: the command postdates POP3
     * by fifteen years, and a server without it advertises nothing — which
     * is how c-client's pop3_capa() reads the same reply.
     *
     * @return list<string>
     */
    private function capa(): array
    {
        fwrite($this->stream, "CAPA\r\n");

        if (!str_starts_with($this->readStatusLine(), '+OK')) {
            return [];
        }

        $capabilities = [];

        while (true) {
            $line = rtrim($this->readStatusLine(), "\r\n");

            if ($line === '.') {
                break;
            }

            $capabilities[] = strtoupper(explode(' ', $line)[0]);
        }

        return $capabilities;
    }

    public function login(string $user, string $password): void
    {
        $this->command('USER '.$user);
        $this->command('PASS '.$password);
    }

    public function stat(): int
    {
        $response = $this->command('STAT');
        $parts = explode(' ', $response);

        return (int) $parts[0];
    }

    public function retr(int $msgno): string
    {
        return $this->joinLines($this->multilineCommand('RETR '.$msgno));
    }

    /**
     * TOP <msgno> <numLines> returns the full header plus the first
     * $numLines lines of the body (0 for headers only).
     */
    public function top(int $msgno, int $numLines): string
    {
        return $this->joinLines($this->multilineCommand('TOP '.$msgno.' '.$numLines));
    }

    /**
     * Every wire line (including the last) is CRLF-terminated; joining with
     * a separator instead of a trailing terminator would silently drop the
     * message's real final CRLF, since that CRLF is indistinguishable from
     * the multiline response's own line terminator.
     *
     * @param string[] $lines
     */
    private function joinLines(array $lines): string
    {
        return $lines === [] ? '' : implode('', array_map(static fn (string $line): string => $line."\r\n", $lines));
    }

    public function dele(int $msgno): void
    {
        $this->command('DELE '.$msgno);
    }

    public function rset(): void
    {
        $this->command('RSET');
    }

    public function noop(): void
    {
        $this->command('NOOP');
    }

    public function quit(): void
    {
        try {
            $this->command('QUIT');
        } finally {
            fclose($this->stream);
        }
    }

    /**
     * One command, one line. The arguments this class formats into a line
     * are its own message numbers except for two, USER's and PASS's, which
     * are whatever imap_open() was handed — a login form's, in the kind of
     * application that reaches for this package. A CR or LF in one of those
     * does not travel as part of it: it ends the command and starts a
     * second one, which is why it is refused rather than sent.
     *
     * Deliberate divergence, in the README's table: pop3.c formats them
     * into its command buffer and sends what they hold.
     */
    private function command(string $line): string
    {
        if (strpbrk($line, "\r\n\0") !== false) {
            throw new \RuntimeException('Command argument contains a line break');
        }

        fwrite($this->stream, $line."\r\n");

        return $this->readSingleLine();
    }

    /**
     * @return string[]
     */
    private function multilineCommand(string $line): array
    {
        fwrite($this->stream, $line."\r\n");
        $this->readSingleLine();

        $lines = [];
        while (($raw = fgets($this->stream)) !== false) {
            $line = rtrim($raw, "\r\n");

            if ($line === '.') {
                break;
            }

            // Byte-stuffing: a line starting with ".." on the wire represents
            // a literal line starting with "." in the message.
            $lines[] = str_starts_with($line, '..') ? substr($line, 1) : $line;
        }

        return $lines;
    }

    private function readSingleLine(): string
    {
        $line = rtrim($this->readStatusLine(), "\r\n");

        if (str_starts_with($line, '+OK')) {
            return trim(substr($line, 3));
        }

        if (str_starts_with($line, '-ERR')) {
            throw new \RuntimeException(trim(substr($line, 4)));
        }

        throw new \RuntimeException('Unexpected POP3 response: '.$line);
    }

    /**
     * One line of the server's own talk — a status line, or one of CAPA's
     * capability lines — read with a ceiling on it.
     *
     * RFC 1939 gives these 512 octets; the ceiling is sixteen times that so
     * no server meets it by being verbose, and it is here because fgets()
     * without a length reads until the line ends, which a server that never
     * ends one turns into the client's whole memory. multilineCommand()
     * reads message data without a ceiling on purpose: a line of a message
     * is as long as whoever sent it made it, and cutting one short would
     * corrupt the message rather than protect anything.
     */
    private function readStatusLine(): string
    {
        $line = fgets($this->stream, self::MAX_STATUS_LINE + 1);

        if ($line === false) {
            throw new \RuntimeException('POP3 connection closed unexpectedly');
        }

        if (!str_ends_with($line, "\n")) {
            // A line that stopped short either met the ceiling or ran out
            // of connection; the second is the one c-client has a word for.
            throw new \RuntimeException(strlen($line) >= self::MAX_STATUS_LINE
                ? 'POP3 status line too long'
                : 'POP3 connection closed unexpectedly');
        }

        return $line;
    }
}
