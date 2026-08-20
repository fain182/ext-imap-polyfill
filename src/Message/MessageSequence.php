<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Connection\UidMode;

/**
 * A message-set string, expanded the way c-client's mail_sequence() and
 * mail_uid_sequence() expand one — including where they refuse to.
 *
 * They refuse in more ways than "that isn't a number", and the wording says
 * which: a first number that is zero or past the end of the folder is out of
 * range, the far end of a range is invalid, a character where a delimiter
 * belongs is a syntax error, and the same character inside a range is a
 * range syntax error. imap_fetch_overview() puts whichever it was on the
 * error stack, so the distinctions are observable and each one is spelled
 * here as c-client spells it.
 *
 * A uid is not a position in the folder, so the uid vocabulary has no upper
 * bound to be past — only zero is refused. The one refusal here that is not
 * c-client's is MAX_EXPANDED's, and it says so where it is spelled.
 */
final class MessageSequence
{
    /** @var array<int, array<string, string>> */
    private const MESSAGES = [
        UidMode::MSGNO => [
            'number' => 'Sequence out of range',
            'rangeEnd' => 'Sequence range invalid',
            'delimiter' => 'Sequence syntax error',
            'rangeDelimiter' => 'Sequence range syntax error',
        ],
        UidMode::UID => [
            'number' => 'UID may not be zero',
            'rangeEnd' => 'UID may not be zero',
            'delimiter' => 'UID sequence syntax error',
            'rangeDelimiter' => 'UID sequence range syntax error',
        ],
    ];

    /** Where no digits were read at all, both vocabularies say the same thing. */
    private const NOT_A_NUMBER = 'Syntax error in sequence';

    /**
     * How many ids one sequence may expand to, over and above the size of
     * the folder it is expanded against.
     *
     * A sequence names messages, but this class answers with the numbers it
     * spans, and the two part company on a range nothing fills: c-client
     * walks the mailbox it has (mail_sequence marks the elements a range
     * covers, mail_uid_sequence keeps the uids it finds), so its answer can
     * never be longer than the folder. Expanding the range itself means
     * "1:4294967295" is four billion ints — not a fetch, an allocation the
     * caller asked for in one argument — and a repeated range multiplies it
     * again, which is why this counts the whole expansion rather than each
     * range. The allowance sits above the folder's own size because uids
     * are sparse: a folder of three messages can legitimately be asked for
     * "1:100000" and answer three.
     */
    private const MAX_EXPANDED = 100000;

    /** The refusal that names, unlike the rest, a limit of this package. */
    private const TOO_MANY = 'Sequence expands to more messages than any mailbox holds';

    private const NO_MAXIMUM = 'No messages, so no maximum message number';

    private function __construct(private readonly string $sequence)
    {
    }

    public static function parse(string $sequence): self
    {
        return new self($sequence);
    }

    /**
     * Expands the set into the numbers it names.
     *
     * @param int $lastId what "*" stands for: the message count in msgno
     *   mode, the highest uid in uid mode
     *
     * @return int[]
     *
     * @throws InvalidSequence with c-client's own wording for the refusal
     */
    public function expand(int $lastId, int $uidMode = UidMode::MSGNO): array
    {
        $ids = [];
        $offset = 0;
        $length = strlen($this->sequence);

        while ($offset < $length) {
            $first = $this->readNumber($offset, $lastId, $uidMode, false);

            // "*" over an empty folder: a msgno sequence has nothing to
            // count to and says so, a uid sequence simply names nothing.
            if ($first === null) {
                return [];
            }

            $delimiter = $this->sequence[$offset] ?? '';

            if ($delimiter === ':') {
                ++$offset;
                $last = $this->readNumber($offset, $lastId, $uidMode, true);

                if ($last === null) {
                    return [];
                }

                $after = $this->sequence[$offset] ?? '';

                if ($after !== '' && $after !== ',') {
                    throw new InvalidSequence(self::MESSAGES[$uidMode]['rangeDelimiter']);
                }

                if ($after === ',') {
                    ++$offset;
                }

                // c-client reads "3:1" as the range it obviously means.
                if ($first > $last) {
                    [$first, $last] = [$last, $first];
                }

                if (count($ids) + ($last - $first + 1) > max($lastId, self::MAX_EXPANDED)) {
                    throw new InvalidSequence(self::TOO_MANY);
                }

                for ($id = $first; $id <= $last; ++$id) {
                    $ids[] = $id;
                }

                continue;
            }

            if ($delimiter === ',' || $delimiter === '') {
                $ids[] = $first;
                $offset += $delimiter === ',' ? 1 : 0;

                continue;
            }

            throw new InvalidSequence(self::MESSAGES[$uidMode]['delimiter']);
        }

        return $ids;
    }

    /**
     * One number or one "*", from $offset, which is left just past it.
     * Answers null where "*" has nothing to stand for in uid mode.
     *
     * @throws InvalidSequence
     */
    private function readNumber(int &$offset, int $lastId, int $uidMode, bool $isRangeEnd): ?int
    {
        if (($this->sequence[$offset] ?? '') === '*') {
            ++$offset;

            if ($lastId >= 1) {
                return $lastId;
            }

            if ($uidMode === UidMode::MSGNO) {
                throw new InvalidSequence(self::NO_MAXIMUM);
            }

            return null;
        }

        $digits = '';
        while (ctype_digit($this->sequence[$offset] ?? '')) {
            $digits .= $this->sequence[$offset];
            ++$offset;
        }

        if ($digits === '') {
            throw new InvalidSequence(self::NOT_A_NUMBER);
        }

        $number = (int) $digits;
        $key = $isRangeEnd ? 'rangeEnd' : 'number';

        if ($number < 1 || ($uidMode === UidMode::MSGNO && $number > $lastId)) {
            throw new InvalidSequence(self::MESSAGES[$uidMode][$key]);
        }

        return $number;
    }
}
