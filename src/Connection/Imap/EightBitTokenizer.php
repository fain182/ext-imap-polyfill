<?php

namespace ImapPolyfill\Connection\Imap;

use DirectoryTree\ImapEngine\Connection\ImapTokenizer;

/**
 * ImapEngine's tokenizer, with 8-bit bytes allowed inside an atom.
 *
 * RFC 3501 writes response text as 7-bit ASCII, and ImapEngine holds
 * servers to it: a byte above 0x7E aborts the whole response. Servers send
 * them anyway — both fixtures quote a rejected mailbox name back verbatim,
 * so a name with an accent in it turns a plain "no such folder" into a
 * parse failure, and leaves the session out of step with the wire.
 *
 * c-client hands whatever bytes arrived to mm_log() without inspecting
 * them, which is what imap_errors() then reports. Only atoms need the
 * tolerance: every delimiter that ends one is ASCII, so admitting the high
 * range extends what an atom may contain and changes nothing about where
 * it stops. Control bytes stay rejected.
 */
final class EightBitTokenizer extends ImapTokenizer
{
    protected function isValidAtomCharacter(string $char): bool
    {
        $code = ord($char);

        if ($code < 32 || $code === 127) {
            return false;
        }

        return !$this->isDelimiter($char);
    }
}
