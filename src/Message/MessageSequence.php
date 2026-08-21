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
     * How many message numbers one sequence may list, over and above the
     * folder it is read against. Nothing a caller means reaches it: a msgno
     * past the count is refused already, so only a set repeating what fits
     * can pile up — c-client marks the messages a set names, and marking one
     * twice costs it nothing.
     */
    private const MAX_EXPANDED = 100000;

    /** The one refusal here that is this package's, not c-client's. */
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
     * The message numbers the set names.
     *
     * @return int[]
     *
     * @throws InvalidSequence with c-client's own wording for the refusal
     */
    public function messageNumbers(int $exists): array
    {
        $ids = [];

        $collect = function (int $first, int $last) use ($exists, &$ids): void {
            // A msgno is refused past the count, so a range cannot outrun
            // the folder; a sequence repeating one can, and c-client marks
            // the messages a set names rather than listing them, so
            // repetition costs it nothing at all.
            if (count($ids) + ($last - $first + 1) > max($exists, self::MAX_EXPANDED)) {
                throw new InvalidSequence(self::TOO_MANY);
            }

            for ($id = $first; $id <= $last; ++$id) {
                $ids[] = $id;
            }
        };

        $this->walk($exists, UidMode::MSGNO, $collect);

        return $ids;
    }

    /**
     * The uids the set names that the folder actually holds — c-client's
     * mail_uid_sequence(), which walks the messages and keeps the ones a
     * range covers rather than expanding the range itself. So "*" is the
     * last message's uid, a uid nobody has is simply absent, and
     * "1:4294967295" costs what the folder costs.
     *
     * @param array<int, int> $folderUids msgno => uid, as the folder holds them
     *
     * @return int[]
     *
     * @throws InvalidSequence with c-client's own wording for the refusal
     */
    public function uids(array $folderUids): array
    {
        // A range has to meet every message, the way mail_uid_sequence()
        // does; a lone uid is a question about one, and asking the folder
        // for it once beats walking it again per term.
        $present = array_flip($folderUids);
        $ids = [];

        $collect = function (int $first, int $last) use ($folderUids, $present, &$ids): void {
            if ($first === $last) {
                if (isset($present[$first])) {
                    $ids[] = $first;
                }

                return;
            }

            foreach ($folderUids as $uid) {
                if ($uid >= $first && $uid <= $last) {
                    $ids[] = $uid;
                }
            }
        };

        $this->walk((int) end($folderUids), UidMode::UID, $collect);

        return $ids;
    }

    /**
     * Reads the set term by term, handing each one to $collect as the range
     * it covers — a lone number being the range of itself. A "*" with
     * nothing to stand for abandons the whole set, which by then can only
     * be an empty one: the folder it is read against has no messages.
     *
     * @param int      $lastId  what "*" stands for
     * @param \Closure(int, int): void $collect
     *
     * @throws InvalidSequence
     */
    private function walk(int $lastId, int $uidMode, \Closure $collect): void
    {
        $offset = 0;
        $length = strlen($this->sequence);

        while ($offset < $length) {
            $first = $this->readNumber($offset, $lastId, $uidMode, false);

            // "*" over an empty folder: a msgno sequence has nothing to
            // count to and says so, a uid sequence simply names nothing.
            if ($first === null) {
                return;
            }

            $delimiter = $this->sequence[$offset] ?? '';

            if ($delimiter === ':') {
                ++$offset;
                $last = $this->readNumber($offset, $lastId, $uidMode, true);

                if ($last === null) {
                    return;
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

                $collect($first, $last);

                continue;
            }

            if ($delimiter === ',' || $delimiter === '') {
                $collect($first, $first);
                $offset += $delimiter === ',' ? 1 : 0;

                continue;
            }

            throw new InvalidSequence(self::MESSAGES[$uidMode]['delimiter']);
        }
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
