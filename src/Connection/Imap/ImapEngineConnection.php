<?php

namespace ImapPolyfill\Connection\Imap;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Streams\StreamInterface;
use DirectoryTree\ImapEngine\Connection\Responses\Data\Data;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Support\Str;
use ImapPolyfill\Connection\CommandFailedException;
use ImapPolyfill\Support\ErrorStack;

/**
 * ImapEngine's connection with its send/collect cycle exposed.
 *
 * ImapEngine keeps each command's responses behind a protected $result, so
 * the commands it has no method for — LSUB, msgno-space SEARCH/FETCH/STORE/
 * COPY, SETQUOTA — cannot be issued through its public API at all. This is
 * the only extension point the polyfill needs; the response shapes the
 * imap_* layer expects are built in Connection\Protocol.
 */
final class ImapEngineConnection extends ImapConnection
{
    private ?int $exists = null;

    private ?int $recent = null;

    /** @var list<string>|null */
    private ?array $capabilities = null;

    private bool $upgraded = false;

    /**
     * Response text arrives as the server wrote it, 8-bit bytes included;
     * see EightBitTokenizer for why ImapEngine's own would refuse it.
     */
    protected function newTokenizer(StreamInterface $stream): ImapTokenizer
    {
        return new EightBitTokenizer($stream);
    }

    /**
     * @param list<string|array{0: string, 1: string}> $tokens
     *
     * @return ResponseCollection<array-key, \DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse>
     */
    public function sendAndCollect(string $command, array $tokens = []): ResponseCollection
    {
        $this->send($command, $tokens, $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged();
    }

    /**
     * c-client upgrades whenever it can (imap4r1.c): STARTTLS goes out on
     * any connection the spec doesn't forbid it on, and a spec that asked
     * for /tls and found no server to negotiate with fails rather than
     * carrying on in the clear.
     *
     * @param bool $required  the spec said /tls
     * @param bool $forbidden the spec said /notls
     */
    public function upgradeToTls(bool $required, bool $forbidden): void
    {
        if ($forbidden) {
            return;
        }

        if (in_array('STARTTLS', $this->capabilities(), true)) {
            $this->startTls();

            return;
        }

        if ($required) {
            throw new \RuntimeException('Unable to negotiate TLS with this server');
        }
    }

    /**
     * ImapEngine's own STARTTLS, with the handshake's answer read.
     *
     * It sends the command, asserts the tagged response, and then throws away
     * what stream_socket_enable_crypto() returned — so a certificate the
     * client rejects leaves a connection that carries on in cleartext, which
     * is the one outcome /tls exists to prevent.
     */
    public function startTls(): void
    {
        $this->send('STARTTLS', tag: $tag);

        $this->assertTaggedResponse($tag);

        if ($this->stream->setSocketSetCrypto(true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            throw new \RuntimeException('Unable to negotiate TLS with this server');
        }

        $this->upgraded = true;

        // The server answers a logged-out stranger and an encrypted client
        // differently; c-client re-reads CAPABILITY here for that reason.
        $this->forgetCapabilities();
    }

    /**
     * Whether this connection reached TLS through STARTTLS rather than
     * having been encrypted from its first byte — c-client's LOCAL->tlsflag,
     * which is what the reported Mailbox string spells "/tls".
     */
    public function upgradedToTls(): bool
    {
        return $this->upgraded;
    }

    /**
     * Certificate validation has to be in the context from the start: the
     * socket is opened in cleartext and only meets a certificate later, at
     * the STARTTLS upgrade, by which time the context is fixed. ImapEngine
     * sets these for implicitly encrypted transports only, so
     * /novalidate-cert would go missing on exactly the upgraded connections.
     *
     * @param array<string, mixed> $proxy
     *
     * @return array<string, mixed>
     */
    protected function getDefaultSocketOptions(string $transport, array $proxy = [], bool $validateCert = true): array
    {
        $options = parent::getDefaultSocketOptions($transport, $proxy, $validateCert);

        $options['ssl'] = [
            'verify_peer' => $validateCert,
            'verify_peer_name' => $validateCert,
        ] + ($options['ssl'] ?? []);

        return $options;
    }

    /**
     * What CAPABILITY last reported, cached like c-client's stream->cap: the
     * command goes out once and its answer is read by everything that gates
     * on it.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->capabilities ??= array_values(
            array_map('strval', $this->capability()->tokensAfter(2))
        );
    }

    /**
     * Called where c-client clears LOCAL->gotcapability — around STARTTLS and
     * authentication, after which a server may well advertise more than it
     * did to a stranger.
     */
    public function forgetCapabilities(): void
    {
        $this->capabilities = null;
    }

    /**
     * c-client's imap_anon(): the anonymous convention wants a contact
     * address, and what it offers is the client's own host name.
     *
     * Divergence: imap_anon() prefers AUTHENTICATE ANONYMOUS wherever the
     * server advertises it, and falls back to this LOGIN only otherwise.
     * This package speaks no SASL, so the fallback is the only form it has.
     */
    public function loginAnonymous(string $localHost): void
    {
        $this->sendAndCollect('LOGIN ANONYMOUS', [Str::literal($localHost)]);
    }

    /**
     * c-client logs the tagged response's own text and nothing else — no
     * tag, no status, no echo of the command — and that text is what
     * imap_errors()/imap_last_error() report. ImapEngine quotes the whole
     * exchange in its exception message instead, so every rejected command
     * is re-raised here.
     *
     * The caller's own exception factory is deliberately discarded: the
     * only one ImapEngine passes is login()'s, which exists to redact the
     * command it echoes, and the server's response text never carries the
     * password to begin with.
     */
    protected function assertTaggedResponse(string $tag, ?callable $exception = null): TaggedResponse
    {
        return parent::assertTaggedResponse($tag, static fn (TaggedResponse $response) => new CommandFailedException(
            (string) ($response->tokenAt(1) ?? ''),
            implode(' ', array_map('strval', $response->tokensAfter(2))),
        ));
    }

    /**
     * Every reply the connection parses passes through here, which is where
     * c-client calls mm_notify() — the hook php_imap.c uses to fill the
     * imap_alerts() stack. It has to sit this low: alerts arrive unsolicited,
     * including in the greeting, and most are dropped by the response filter
     * of whatever command happened to be in flight.
     */
    protected function nextReply(): Data|Token|Response|null
    {
        $reply = parent::nextReply();

        if ($reply instanceof UntaggedResponse) {
            $this->absorbCounts($reply);

            if (($alert = self::alertText($reply)) !== null) {
                ErrorStack::pushAlert($alert);
            }
        }

        return $reply;
    }

    /**
     * Message counts as last reported for the selected folder, or null when
     * nothing has reported them since the folder was selected.
     *
     * @return array{exists: ?int, recent: ?int}
     */
    public function counts(): array
    {
        return ['exists' => $this->exists, 'recent' => $this->recent];
    }

    /**
     * Called when the selection changes: counts describe one folder only.
     */
    public function forgetCounts(): void
    {
        $this->exists = null;
        $this->recent = null;
    }

    /**
     * c-client folds "* n EXISTS", "* n RECENT" and "* n EXPUNGE" into its
     * stream cache wherever they turn up — they are not tied to SELECT, and
     * a server may volunteer them on any command. Tracking them here is what
     * lets the polyfill report counts without re-selecting the folder first.
     *
     * EXPUNGE carries the message number that went away, not a new total, so
     * the count is decremented rather than replaced.
     */
    private function absorbCounts(UntaggedResponse $response): void
    {
        $keyword = (string) ($response->tokenAt(2) ?? '');
        $number = (int) (string) $response->type();

        match ($keyword) {
            'EXISTS' => $this->exists = $number,
            'RECENT' => $this->recent = $number,
            'EXPUNGE' => $this->exists = max(0, ($this->exists ?? 1) - 1),
            default => null,
        };
    }

    /**
     * The alert php_imap.c's mm_notify() would record, prefix and all — it
     * stores the untouched response text, and only when it literally starts
     * with "[ALERT] ".
     *
     * c-client notifies on untagged OK/PREAUTH/NO/BAD/BYE only: tagged
     * replies reach imap_parse_response() with ntfy off (imap4r1.c), so an
     * "[ALERT]" on a command's own completion line is never recorded.
     */
    private static function alertText(UntaggedResponse $response): ?string
    {
        if (!in_array((string) $response->type(), ['OK', 'PREAUTH', 'NO', 'BAD', 'BYE'], true)) {
            return null;
        }

        $code = $response->tokenAt(2);
        if (!$code instanceof ResponseCodeData || (string) $code !== '[ALERT]') {
            return null;
        }

        // "[ALERT]" with nothing after it never matches mm_notify's
        // "[ALERT] " comparison, trailing space included.
        $text = $response->tokensAfter(3);

        if ($text === []) {
            return null;
        }

        // Reassembled from the parsed tokens, since the raw line is gone by
        // now: parentheses and quoting survive the round trip, but a run of
        // whitespace inside the text collapses to a single space, where
        // c-client hands the line to mm_notify() untouched.
        return '[ALERT] '.implode(' ', array_map('strval', $text));
    }
}
