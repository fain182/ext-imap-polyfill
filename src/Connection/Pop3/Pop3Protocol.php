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
    /** @var resource */
    private $stream;

    public function connect(
        string $host,
        int $port,
        string $encryption,
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

        if ($encryption === 'starttls') {
            $this->startTls();
        }
    }

    /**
     * RFC 2595 STLS. Throws rather than carrying on unencrypted: /tls is a
     * requirement, not a preference.
     */
    private function startTls(): void
    {
        $this->command('STLS');

        $crypto = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_ANY_CLIENT);

        if ($crypto !== true) {
            throw new \RuntimeException('Unable to negotiate TLS with this server');
        }
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

    private function command(string $line): string
    {
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
        $line = fgets($this->stream);

        if ($line === false) {
            throw new \RuntimeException('POP3 connection closed unexpectedly');
        }

        $line = rtrim($line, "\r\n");

        if (str_starts_with($line, '+OK')) {
            return trim(substr($line, 3));
        }

        if (str_starts_with($line, '-ERR')) {
            throw new \RuntimeException(trim(substr($line, 4)));
        }

        throw new \RuntimeException('Unexpected POP3 response: '.$line);
    }
}
