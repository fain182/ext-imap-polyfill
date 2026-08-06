<?php

namespace ImapPolyfill\Connection\Imap;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\Data;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
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
     * Every reply the connection parses passes through here, which is where
     * c-client calls mm_notify() — the hook php_imap.c uses to fill the
     * imap_alerts() stack. It has to sit this low: alerts arrive unsolicited,
     * including in the greeting, and most are dropped by the response filter
     * of whatever command happened to be in flight.
     */
    protected function nextReply(): Data|Token|Response|null
    {
        $reply = parent::nextReply();

        if ($reply instanceof UntaggedResponse && ($alert = self::alertText($reply)) !== null) {
            ErrorStack::pushAlert($alert);
        }

        return $reply;
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
