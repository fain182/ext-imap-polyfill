<?php

namespace ImapPolyfill\Connection\Imap;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\Data;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
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
