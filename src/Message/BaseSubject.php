<?php

namespace ImapPolyfill\Message;

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
     * @return array{string, bool} [base subject, subject had a re/fwd]
     */
    private static function strip(string $subject): array
    {
        if ($subject === '') {
            return ['', false];
        }

        // Step 1: tabs become spaces, whitespace runs collapse to one.
        $s = preg_replace('/[\t ]+/', ' ', $subject) ?? $subject;

        $refwd = false;
        while (true) {
            // Step 2: strip trailing WSP and "(fwd)", repeatedly.
            while (true) {
                if (str_ends_with($s, ' ')) {
                    $s = substr($s, 0, -1);
                    continue;
                }
                if (strlen($s) >= 5 && strcasecmp(substr($s, -5), '(fwd)') === 0) {
                    $s = substr($s, 0, -5);
                    $refwd = true;
                    continue;
                }
                break;
            }

            // Steps 3-5: strip leading WSP, re/fwd markers, and blobs.
            $i = 0;
            $length = strlen($s);
            while ($i < $length) {
                $char = $s[$i];
                if ($char === ' ') {
                    $i = self::afterWsp($s, $i + 1);
                    continue;
                }
                if ($char === 'r' || $char === 'R') {
                    $after = self::afterRefwd($s, $i, 're');
                    if ($after === null) {
                        break;
                    }
                    $i = $after;
                    $refwd = true;
                    continue;
                }
                if ($char === 'f' || $char === 'F') {
                    $after = self::afterRefwd($s, $i, 'fwd') ?? self::afterRefwd($s, $i, 'fw');
                    if ($after === null) {
                        break;
                    }
                    $i = $after;
                    $refwd = true;
                    continue;
                }
                if ($char === '[') {
                    $after = self::afterBlob($s, $i);
                    // Only strip a bare blob when it leaves a non-empty base.
                    if ($after === null || $after >= $length) {
                        break;
                    }
                    $i = $after;
                    continue;
                }
                break;
            }
            $s = substr($s, $i);

            // Step 6: unwrap "[Fwd: ...]" and start over from step 2.
            if (preg_match('/^\[fwd:/i', $s) === 1 && str_ends_with($s, ']')) {
                $s = substr($s, 5, -1);
                $refwd = true;
                continue;
            }

            return [$s, $refwd];
        }
    }

    /**
     * Position after "re"/"fw"/"fwd" + WSP + optional [blob] + ":", or
     * null when $s at $i isn't that marker.
     */
    private static function afterRefwd(string $s, int $i, string $marker): ?int
    {
        if (strcasecmp(substr($s, $i, strlen($marker)), $marker) !== 0) {
            return null;
        }

        $after = self::afterBlob($s, self::afterWsp($s, $i + strlen($marker)));

        return $after !== null && ($s[$after] ?? '') === ':' ? $after + 1 : null;
    }

    private static function afterWsp(string $s, int $i): int
    {
        while (($s[$i] ?? '') === ' ') {
            $i++;
        }

        return $i;
    }

    /**
     * Position after a "[...]" blob plus trailing WSP: unchanged when $s at
     * $i isn't a blob, null when it's blob-like but malformed (embedded "["
     * or unterminated) — which voids the whole surrounding marker, like
     * mail_strip_subject_blob() returning NIL.
     */
    private static function afterBlob(string $s, int $i): ?int
    {
        if (($s[$i] ?? '') !== '[') {
            return $i;
        }

        while (($char = $s[++$i] ?? '') !== ']') {
            if ($char === '[' || $char === '') {
                return null;
            }
        }

        return self::afterWsp($s, $i + 1);
    }
}
