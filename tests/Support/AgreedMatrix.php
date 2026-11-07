<?php

namespace ImapPolyfill\Tests\Support;

/**
 * What both fixtures answer the same way, and what they don't.
 *
 * A generated fixture has to record ext-imap's behaviour rather than one
 * server's: a STORE against a message set the folder cannot have is
 * refused by Dovecot and shrugged off by Greenmail, and pinning either
 * answer would tie the suite to whichever server was running when the file
 * was written. So every matrix is measured twice and only the agreeing
 * cells are kept — with the rest named in the file, so the omission is
 * visible rather than silent.
 */
final class AgreedMatrix
{
    /**
     * The callback gets each server's spec base, `{host:port/flags}`, to
     * append folder names to.
     *
     * @param callable(string): array<string, array<string, mixed>> $measure keyed group => cell => outcome
     * @param string                                               $label   sprintf template taking cell then group
     *
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>} [kept, excluded]
     */
    public static function of(callable $measure, string $label): array
    {
        $primary = $measure(MailServer::greenmail()->spec());
        $second = $measure(MailServer::dovecot()->spec());

        $kept = [];
        $excluded = [];

        foreach ($primary as $group => $cells) {
            foreach ($cells as $cell => $outcome) {
                if ($outcome === ($second[$group][$cell] ?? null)) {
                    $kept[$group][$cell] = $outcome;

                    continue;
                }

                $excluded[] = sprintf($label, $cell, $group);
            }
        }

        return [$kept, $excluded];
    }

    /**
     * The paragraph the generated file carries about what was left out.
     *
     * @param list<string> $excluded
     */
    public static function omissionNote(array $excluded): string
    {
        if ($excluded === []) {
            return ' * The two servers agreed on every cell.';
        }

        return " * Left out, because the two servers disagree and the answer is\n"
            ." * therefore theirs rather than ext-imap's:\n *\n *   - "
            .implode("\n *   - ", $excluded);
    }
}
