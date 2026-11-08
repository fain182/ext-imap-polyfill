<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Mime\MimeText;

/**
 * Port of c-client's mail_strip_subject() (mail.c), the RFC 5256 §2.1 base
 * subject algorithm: collapse whitespace, strip trailing "(fwd)"/WSP
 * (step 2), strip leading WSP, "re"/"fw"/"fwd" markers with an optional
 * "[blob]" before the colon, and "[blob]" prefixes that leave a non-empty
 * base (steps 3-5), then unwrap a Netscape-style "[Fwd: ...]" and start
 * over (step 6). Used by both imap_sort(SORTSUBJECT) and imap_thread()'s
 * subject-gathering step.
 */
final class BaseSubject
{
    /**
     * Mirrors c-client's SORTCACHE->refwd: whether stripping found any
     * re/fwd marker — a "(fwd)" trailer, a re/fw/fwd leader, or a
     * "[Fwd: ...]" wrapper — independent of whether the message has
     * References/In-Reply-To headers.
     */
    public static function isReplyOrForward(string $subject): bool
    {
        return self::strip($subject)[1];
    }

    public static function of(string $subject): string
    {
        // Lowercasing isn't part of mail_strip_subject() — c-client
        // compares base subjects case-insensitively afterwards, which
        // grouping and ordering on the lowercased form reproduces.
        return strtolower(self::strip($subject)[0]);
    }

    /**
     * mail_strip_subject() on the C buffer it works in, because one of its
     * answers depends on that buffer. Step 1 collapses whitespace in place,
     * but step 2's first pass starts from the length measured *before* the
     * collapse: when step 1 shortened the string, that pass begins on the
     * terminator, or on what the collapse left behind it, and strips
     * nothing. "Hello  world (fwd)" keeps its "(fwd)" for that reason, and
     * does not thread with "Hello world".
     *
     * @return array{string, bool} [base subject, subject had a re/fwd]
     */
    private static function strip(string $subject): array
    {
        // src.size is strlen(): a NUL ends the subject.
        $nul = strpos($subject, "\0");
        $subject = $nul === false ? $subject : substr($subject, 0, $nul);

        if ($subject === '') {
            return ['', false];
        }

        // Step 1 starts from the decoded subject (utf8_mime2text()), so an
        // encoded "Re:" is a reply like any other. c-client also folds the
        // case of the decoded text and decomposes it; that changes the bytes
        // of the same text and nothing a caller sees, and is left out.
        $subject = MimeText::decode($subject);

        $size = strlen($subject);
        $buffer = $subject."\0";

        // Step 1: tabs become spaces, and a run of whitespace keeps only its
        // first character.
        for ($x = 0, $t = 0, $c = 'x'; $t < $size; $t++) {
            if ($c !== ' ') {
                $c = $buffer[$x++] = $buffer[$t] === "\t" ? ' ' : $buffer[$t];
            } elseif ($buffer[$t] !== "\t" && $buffer[$t] !== ' ') {
                $c = $buffer[$x++] = $buffer[$t];
            }
        }
        $buffer[$x] = "\0";

        $refwd = false;
        $s = 0;

        for ($length = $size; ; $length = self::length($buffer, $s)) {
            // Step 2: strip trailing WSP and "(fwd)", repeatedly.
            for ($t = $s + $length; $t > $s;) {
                $char = $buffer[$t - 1];

                if ($char === ' ' || $char === "\t") {
                    $buffer[--$t] = "\0";
                } elseif ($char === ')' && $t >= $s + 5 && $buffer[$t - 5] === '('
                    && strcasecmp(substr($buffer, $t - 4, 3), 'fwd') === 0) {
                    $buffer[$t -= 5] = "\0";
                    $refwd = true;
                } else {
                    break;
                }
            }

            // Steps 3-5: strip leading WSP, re/fwd markers, and blobs.
            while (true) {
                $char = $buffer[$s];

                if ($char === ' ' || $char === "\t") {
                    $s = self::afterWsp($buffer, $s + 1);
                } elseif (($char === 'r' || $char === 'R') && ($buffer[$s + 1] === 'e' || $buffer[$s + 1] === 'E')
                    && ($t = self::afterBlob($buffer, self::afterWsp($buffer, $s + 2))) !== null
                    && $buffer[$t] === ':') {
                    $s = $t + 1;
                    $refwd = true;
                } elseif (($char === 'f' || $char === 'F') && ($buffer[$s + 1] === 'w' || $buffer[$s + 1] === 'W')
                    && ($t = self::afterBlob($buffer, self::afterWsp(
                        $buffer,
                        $buffer[$s + 2] === 'd' || $buffer[$s + 2] === 'D' ? $s + 3 : $s + 2,
                    ))) !== null
                    && $buffer[$t] === ':') {
                    $s = $t + 1;
                    $refwd = true;
                } elseif ($char === '[' && ($t = self::afterBlob($buffer, $s)) !== null && $buffer[$t] !== "\0") {
                    // A bare blob goes only when something is left after it.
                    $s = $t;
                } else {
                    break;
                }
            }

            // Step 6: unwrap a Netscape-style "[Fwd: ...]" and start over
            // from step 2.
            $last = $s + self::length($buffer, $s) - 1;

            if ($buffer[$s] === '[' && strcasecmp(substr($buffer, $s + 1, 3), 'fwd') === 0
                && $buffer[$s + 4] === ':' && $buffer[$last] === ']') {
                $buffer[$last] = "\0";
                $s += 5;
                $refwd = true;

                continue;
            }

            return [substr($buffer, $s, self::length($buffer, $s)), $refwd];
        }
    }

    /**
     * strlen() of the C string starting at $at.
     */
    private static function length(string $buffer, int $at): int
    {
        return strcspn($buffer, "\0", $at);
    }

    private static function afterWsp(string $buffer, int $i): int
    {
        while ($buffer[$i] === ' ' || $buffer[$i] === "\t") {
            $i++;
        }

        return $i;
    }

    /**
     * mail_strip_subject_blob(): the position after a "[...]" blob and the
     * WSP behind it, $i itself when there is no blob there, null when it is
     * blob-like but not a blob (a "[" inside, or no "]" before the end) —
     * which voids the whole marker around it.
     */
    private static function afterBlob(string $buffer, int $i): ?int
    {
        if ($buffer[$i] !== '[') {
            return $i;
        }

        while (($char = $buffer[++$i]) !== ']') {
            if ($char === '[' || $char === "\0") {
                return null;
            }
        }

        return self::afterWsp($buffer, $i + 1);
    }
}
