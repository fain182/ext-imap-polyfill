<?php

namespace ImapPolyfill\Message;

/**
 * Practical subset of RFC5256 §2.1's base subject algorithm: strips a
 * leading Re:/Fwd:/Fw: (optionally with a "[N]" reply count), a leading
 * "[mailing-list-tag]" blob, and a trailing "(fwd)", repeating until no
 * more strip, then lowercases and collapses whitespace. Used by both
 * imap_sort(SORTSUBJECT) and imap_thread()'s subject-gathering step.
 *
 * Not the full ABNF grammar (subj-trailer/subj-leader/subj-blob), which
 * also handles trailing "(fwd)" chains combined with bracket blobs, etc. —
 * documented as a divergence.
 */
final class BaseSubject
{
    /**
     * Mirrors c-client's SORTCACHE->refwd: whether the subject carried a
     * leading Re:/Fwd:/Fw: (with optional "[N]" reply count) or a trailing
     * "(fwd)" — independent of whether the message has References/
     * In-Reply-To headers.
     */
    public static function isReplyOrForward(string $subject): bool
    {
        $text = trim(preg_replace('/[\t ]+/', ' ', $subject) ?? $subject);

        return preg_match('/^\s*(re|fwd?)(\[\d+\])?\s*:\s*/i', $text) === 1
            || preg_match('/\(fwd\)\s*$/i', $text) === 1;
    }

    public static function of(string $subject): string
    {
        $text = trim(preg_replace('/[\t ]+/', ' ', $subject) ?? $subject);

        do {
            $changed = false;

            $stripped = preg_replace('/\s*\(fwd\)\s*$/i', '', $text) ?? $text;
            if ($stripped !== $text) {
                $text = $stripped;
                $changed = true;
            }

            $stripped = preg_replace('/^\s*(re|fwd?)(\[\d+\])?\s*:\s*/i', '', $text) ?? $text;
            if ($stripped !== $text) {
                $text = $stripped;
                $changed = true;
            }

            $stripped = preg_replace('/^\s*\[[^\]]*\]\s*/', '', $text) ?? $text;
            if ($stripped !== '' && $stripped !== $text) {
                $text = $stripped;
                $changed = true;
            }
        } while ($changed);

        return strtolower(trim($text));
    }
}
