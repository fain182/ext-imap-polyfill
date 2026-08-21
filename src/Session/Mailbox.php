<?php

namespace ImapPolyfill\Session;

use ImapPolyfill\Connection\FolderState;
use ImapPolyfill\Connection\MessageNotFoundException;
use ImapPolyfill\Connection\UidMode;
use ImapPolyfill\Mailbox\MailboxReference;
use ImapPolyfill\Message\BodyStructure;
use ImapPolyfill\Message\HeaderInfo;
use ImapPolyfill\Message\HeadersLine;
use ImapPolyfill\Message\MessageSequence;
use ImapPolyfill\Message\Overview;
use ImapPolyfill\Message\SearchProgram;
use ImapPolyfill\Message\SortCriterion;
use ImapPolyfill\Message\SortKey;
use ImapPolyfill\Message\ThreadBuilder;
use ImapPolyfill\Support\ErrorStack;

/**
 * Operations on the mailbox currently selected on an open \IMAP\Connection:
 * searching, fetching, flagging, and appending messages within it.
 */
final class Mailbox
{
    public function __construct(private readonly \IMAP\Connection $connection)
    {
    }

    /**
     * @return int[]|false
     */
    public function search(string $criteria, int $flags, string $charset): array|false
    {
        if ($flags !== 0 && ($flags & ~(SE_UID | SE_FREE)) !== 0) {
            throw new \ValueError('imap_search(): Argument #3 ($flags) must be a bitmask of SE_FREE, and SE_UID');
        }

        $uidMode = UidMode::fromFlags($flags, SE_UID);

        $this->connection->ensureOpen();

        // c-client parses the criteria into a SEARCHPGM before anything goes
        // out, and a keyword its struct has no field for fails the search
        // rather than reaching a server that might have understood it.
        try {
            $program = SearchProgram::parse($criteria);
        } catch (\RuntimeException $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        // Nothing to look through: c-client answers from the count it
        // holds rather than asking, so no SEARCH goes out and a folder
        // that has gone away is not an error, just empty.
        $status = $this->selectedFolder();

        if ($status === false || $status->exists < 1) {
            return false;
        }

        try {
            $ids = $this->connection->backend()->search($program, $uidMode, $charset);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        if ($ids === []) {
            return false;
        }

        return array_map('intval', $ids);
    }

    /**
     * Selects the folder and answers its status, or false when
     * $messageNum is past the end of it.
     *
     * c-client checks the number against the count it already holds and
     * answers from that: past the end is simply absent, so no FETCH goes
     * out and nothing reaches the error stack — it warns instead, naming
     * the function the caller entered through. A UID is not a position in
     * the folder, so it is not checked this way. A selection that fails
     * outright is another matter, and is reported as any failure is.
     */
    private function selectionCovering(int $messageNum, int $uidMode, string $function): FolderState|false
    {
        $status = $this->selectedFolder();

        if ($status === false) {
            return false;
        }

        if ($uidMode !== UidMode::UID && $messageNum > $status->exists) {
            return $this->absent($function, $uidMode);
        }

        return $status;
    }

    /**
     * The selected folder as c-client holds it, or false with the failure
     * on the error stack. Says nothing about any particular message.
     */
    private function selectedFolder(): FolderState|false
    {
        try {
            return $this->connection->selectOrExamine();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }
    }

    /**
     * The message is not there. c-client answers that from the cache it
     * already holds rather than from a failed FETCH, so php_imap.c warns
     * (php_error_docref) and leaves the error stack alone.
     *
     * Always false, so callers can `return $this->absent(...)` whatever
     * they return alongside it. Declared bool because a standalone false
     * return type needs PHP 8.2 and this package supports 8.1.
     *
     * @return false
     */
    private function absent(string $function, int $uidMode): bool
    {
        trigger_error(
            $uidMode === UidMode::UID
                ? "{$function}(): UID does not exist"
                : "{$function}(): Bad message number",
            E_USER_WARNING,
        );

        return false;
    }

    /**
     * Whether the folder has no message with this uid.
     *
     * c-client settles that from the uid cache it already holds, before it
     * builds any command, so a uid that is not there is answered as absent
     * however malformed the rest of the call was. This is the same question
     * asked the other way round: only on the path where the fetch already
     * failed, so the happy path stays one round trip.
     */
    private function uidIsAbsent(int $uid): bool
    {
        // An empty folder has no uids, and asking it for the ones it has is
        // itself an error on some servers ("1:*" over nothing).
        if ($this->connection->numMessages() < 1) {
            return true;
        }

        try {
            $this->connection->backend()->getMessageNumber((string) $uid);
        } catch (MessageNotFoundException) {
            return true;
        } catch (\Throwable) {
            // No answer either way: the failure that got us here stands.
            return false;
        }

        return false;
    }

    /**
     * One FETCH item for one message, which is the whole of what
     * imap_body(), imap_fetchbody(), imap_fetchmime(), imap_fetchheader()
     * and imap_savebody() ask the server for.
     *
     * The message being absent is answered as absence rather than as
     * failure, in the two shapes a server reports it: an empty response,
     * and — because a UID was never checked against the count up front —
     * a rejected FETCH whose uid the folder turns out not to have.
     */
    private function fetchItem(int $messageNum, string $item, int $uidMode, string $function): string|false
    {
        if ($this->selectionCovering($messageNum, $uidMode, $function) === false) {
            return false;
        }

        try {
            $data = $this->connection->backend()->fetch([$item], [$messageNum], null, $uidMode);
        } catch (\Throwable $e) {
            if ($uidMode === UidMode::UID && $this->uidIsAbsent($messageNum)) {
                return $this->absent($function, $uidMode);
            }

            ErrorStack::push($e->getMessage());

            return false;
        }

        if ($data === []) {
            return $this->absent($function, $uidMode);
        }

        return $data[$messageNum] ?? reset($data);
    }

    public function fetchHeader(int $messageNum, int $flags): string|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_fetchheader(): Argument #2 ($message_num) must be greater than 0');
        }

        if ($flags !== 0 && ($flags & ~(FT_UID | FT_PREFETCHTEXT | FT_INTERNAL)) !== 0) {
            throw new \ValueError('imap_fetchheader(): Argument #3 ($flags) must be a bitmask of FT_UID, FT_PREFETCHTEXT, and FT_INTERNAL');
        }

        return $this->fetchItem($messageNum, 'RFC822.HEADER', UidMode::fromFlags($flags, FT_UID), 'imap_fetchheader');
    }

    public function headerInfo(int $messageNum, int $fromLength = 0, int $subjectLength = 0): \stdClass|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_headerinfo(): Argument #2 ($message_num) must be greater than 0');
        }

        // 1024 is c-client's MAILTMPLEN, the buffer php_imap.c formats into.
        if ($fromLength < 0 || $fromLength > 1024) {
            throw new \ValueError('imap_headerinfo(): Argument #3 ($from_length) must be between 0 and 1024');
        }

        if ($subjectLength < 0 || $subjectLength > 1024) {
            throw new \ValueError('imap_headerinfo(): Argument #4 ($subject_length) must be between 0 and 1024');
        }

        if ($this->selectionCovering($messageNum, UidMode::MSGNO, 'imap_headerinfo') === false) {
            return false;
        }

        try {
            $data = $this->connection->backend()->fetch(
                HeaderInfo::FETCH_ITEMS,
                [$messageNum],
                null,
                UidMode::MSGNO,
            );
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $message = $data[$messageNum] ?? reset($data);

        if (!is_array($message)) {
            return false;
        }

        return HeaderInfo::build(
            $message['RFC822.HEADER'],
            $message['FLAGS'],
            $message['INTERNALDATE'],
            $message['RFC822.SIZE'],
            $messageNum,
            $this->connection->backend()->host(),
            $fromLength,
            $subjectLength,
        );
    }

    /**
     * @return \stdClass[]|false
     */
    public function fetchOverview(string $sequence, int $flags): array|false
    {
        if ($flags !== 0 && ($flags & ~FT_UID) !== 0) {
            throw new \ValueError('imap_fetch_overview(): Argument #3 ($flags) must be FT_UID or 0');
        }

        $uidMode = UidMode::fromFlags($flags, FT_UID);

        $this->connection->ensureOpen();

        try {
            $status = $this->connection->selectOrExamine();
            $exists = $status->exists;

            $protocol = $this->connection->backend();
            $set = MessageSequence::parse($sequence);

            // c-client settles the set before anything goes out: against the
            // count it holds for message numbers, and against the uids it
            // holds for uids, which is why a uid range costs the folder
            // rather than the range.
            $folderUids = $uidMode === UidMode::UID ? $protocol->getUid() : [];
            $ids = $uidMode === UidMode::UID
                ? $set->uids($folderUids)
                : $set->messageNumbers($exists);

            if ($ids === []) {
                return [];
            }

            $data = $protocol->fetch(['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'], $ids, null, $uidMode);

            // msgno => uid reversed is the lookup the loop below needs; the
            // backend would answer it by walking the table per message.
            $msgnos = array_flip($folderUids);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            // Observed real ext-imap behavior: a broken connection yields an
            // empty result set here, not false (unlike most other fetch
            // functions in this file).
            return [];
        }

        $result = [];
        foreach ($ids as $id) {
            if (!isset($data[$id])) {
                continue;
            }

            $message = $data[$id];
            $uid = $uidMode === UidMode::UID ? $id : (int) $message['UID'];
            $msgno = $uidMode === UidMode::UID ? $msgnos[$id] : $id;

            $result[] = Overview::build(
                $message['RFC822.HEADER'],
                $message['FLAGS'],
                $message['INTERNALDATE'],
                (int) $message['RFC822.SIZE'],
                $uid,
                $msgno,
                $this->connection->backend()->host(),
            );
        }

        return $result;
    }

    public function fetchStructure(int $messageNum, int $flags): \stdClass|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_fetchstructure(): Argument #2 ($message_num) must be greater than 0');
        }

        if ($flags !== 0 && ($flags & ~FT_UID) !== 0) {
            throw new \ValueError('imap_fetchstructure(): Argument #3 ($flags) must be FT_UID or 0');
        }

        $uidMode = UidMode::fromFlags($flags, FT_UID);

        if ($this->selectionCovering($messageNum, $uidMode, 'imap_fetchstructure') === false) {
            return false;
        }

        try {
            $parsed = $this->connection->backend()->fetchBodyStructure($messageNum, (bool) ($flags & FT_UID));
        } catch (MessageNotFoundException) {
            return $this->absent('imap_fetchstructure', $uidMode);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return BodyStructure::build($parsed);
    }

    public function fetchBody(int $messageNum, string $section, int $flags): string|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_fetchbody(): Argument #2 ($message_num) must be greater than 0');
        }

        if ($flags !== 0 && ($flags & ~(FT_UID | FT_PEEK | FT_INTERNAL)) !== 0) {
            throw new \ValueError('imap_fetchbody(): Argument #4 ($flags) must be a bitmask of FT_UID, FT_PEEK, and FT_INTERNAL');
        }

        return $this->fetchSection($messageNum, $section, $flags, 'imap_fetchbody');
    }

    /**
     * The body of one section, for the two functions that fetch one:
     * imap_fetchbody() and imap_savebody(), which warn under their own name
     * when the message is not there.
     */
    private function fetchSection(int $messageNum, string $section, int $flags, string $function): string|false
    {
        $uidMode = UidMode::fromFlags($flags, FT_UID);
        // ext-imap's section "0" is a legacy alias for the top-level header,
        // not a literal MIME part index.
        $wireSection = $section === '0' ? 'HEADER' : $section;

        // Asked here and again inside fetchItem() below, because the probe
        // between the two must not run for a message that is not there: it
        // would report the server's refusal where the caller is owed the
        // warning naming the message. Settling it twice costs nothing — the
        // selection is cached, and a message that is absent has answered and
        // returned before the second one.
        if ($this->selectionCovering($messageNum, $uidMode, $function) === false) {
            return false;
        }

        // A numbered section may not be there at all, and c-client answers
        // that from the structure rather than from the server: asking for
        // part 2 of a message that has one part is an empty string, not
        // the body over again. It has to be settled before the body is
        // asked for, not after — a server that cannot read the message at
        // all still owes the same empty string, and one of the fixtures
        // refuses the FETCH outright when the charset means nothing to it.
        //
        // Section 1 is the exception worth making: every structure has
        // one, so the answer is known without asking, and it is the
        // section every caller reaches for first.
        if ($wireSection !== '1' && preg_match('/^\d+(?:\.\d+)*$/', $wireSection) === 1) {
            try {
                $structure = $this->connection->backend()->fetchBodyStructure($messageNum, $uidMode === UidMode::UID);
            } catch (\Throwable $e) {
                ErrorStack::push($e->getMessage());

                return false;
            }

            if (BodyStructure::resolveSection($structure, $wireSection) === null) {
                return '';
            }
        }

        return $this->fetchItem($messageNum, self::bodyItem($wireSection, $flags), $uidMode, $function);
    }

    /**
     * BODY[section], asked for without marking the message \Seen when the
     * caller passed FT_PEEK.
     */
    private static function bodyItem(string $section, int $flags): string
    {
        return ($flags & FT_PEEK) ? "BODY.PEEK[{$section}]" : "BODY[{$section}]";
    }

    public function fetchMime(int $messageNum, string $section, int $flags): string|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_fetchmime(): Argument #2 ($message_num) must be greater than 0');
        }

        if ($flags !== 0 && ($flags & ~(FT_UID | FT_PEEK | FT_INTERNAL)) !== 0) {
            throw new \ValueError('imap_fetchmime(): Argument #4 ($flags) must be a bitmask of FT_UID, FT_PEEK, and FT_INTERNAL');
        }

        return $this->fetchItem(
            $messageNum,
            self::bodyItem("{$section}.MIME", $flags),
            UidMode::fromFlags($flags, FT_UID),
            'imap_fetchmime',
        );
    }

    public function bodyStruct(int $messageNum, string $section): \stdClass|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_bodystruct(): Argument #2 ($message_num) must be greater than 0');
        }

        // c-client's mail_body() indexes a single BODYSTRUCTURE fetch by
        // section, unlike imap_fetchbody(): there is no msgno/uid
        // equivalent of BODYSTRUCTURE for one section, so this is always
        // a msgno, never a uid (no FT_UID here, unlike imap_fetchbody()).
        if ($this->selectionCovering($messageNum, UidMode::MSGNO, 'imap_bodystruct') === false) {
            return false;
        }

        try {
            $parsed = $this->connection->backend()->fetchBodyStructure($messageNum, false);
        } catch (MessageNotFoundException) {
            return $this->absent('imap_bodystruct', UidMode::MSGNO);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $node = BodyStructure::resolveSection($parsed, $section);

        if ($node === null) {
            return false;
        }

        return BodyStructure::build($node);
    }

    /**
     * @param resource|string $file
     */
    public function saveBody(mixed $file, int $messageNum, string $section, int $flags): bool
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_savebody(): Argument #3 ($message_num) must be greater than 0');
        }

        if ($flags !== 0 && ($flags & ~(FT_UID | FT_PEEK | FT_INTERNAL)) !== 0) {
            throw new \ValueError('imap_savebody(): Argument #5 ($flags) must be a bitmask of FT_UID, FT_PEEK, and FT_INTERNAL');
        }

        $uidMode = UidMode::fromFlags($flags, FT_UID);

        // Settled before the destination is touched, the way c-client
        // settles it: from the counts and the uid table it already holds.
        // Opening first would truncate a file the caller asked to fill with
        // a message that was never there.
        if ($this->selectionCovering($messageNum, $uidMode, 'imap_savebody') === false) {
            return false;
        }

        if ($uidMode === UidMode::UID && $this->uidIsAbsent($messageNum)) {
            return $this->absent('imap_savebody', $uidMode);
        }

        $isResource = is_resource($file);
        if ($isResource) {
            $handle = $file;
        } else {
            $handle = @fopen((string) $file, 'wb');
            if ($handle === false) {
                return false;
            }
        }

        // A section that isn't there is written as nothing and still counts
        // as success — ext-imap's C implementation never looks at what
        // mail_fetchbody_full() produced. A message that isn't there is a
        // different answer: that one is settled before any of this, and
        // false is what it gets.
        $body = $this->fetchSection($messageNum, $section, $flags, 'imap_savebody');

        if ($body === false) {
            if (!$isResource) {
                fclose($handle);
            }

            return false;
        }

        fwrite($handle, $body);

        if (!$isResource) {
            fclose($handle);
        }

        return true;
    }

    public function body(int $messageNum, int $flags): string|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_body(): Argument #2 ($message_num) must be greater than 0');
        }

        if (($flags & ~(FT_UID | FT_PEEK | FT_INTERNAL)) !== 0) {
            throw new \ValueError('imap_body(): Argument #3 ($flags) must be a bitmask of FT_UID, FT_PEEK, and FT_INTERNAL');
        }

        return $this->fetchItem(
            $messageNum,
            self::bodyItem('TEXT', $flags),
            UidMode::fromFlags($flags, FT_UID),
            'imap_body',
        );
    }

    public function copy(string $sequence, string $folder, int $options): bool
    {
        $this->connection->ensureOpen();

        if (($options & ~(CP_UID | CP_MOVE)) !== 0) {
            throw new \ValueError('imap_mail_copy(): Argument #4 ($flags) must be a bitmask of CP_UID, and CP_MOVE');
        }

        return $this->copyTo($sequence, $folder, $options);
    }

    public function move(string $sequence, string $folder, int $options): bool
    {
        $this->connection->ensureOpen();

        if (($options & ~CP_UID) !== 0) {
            throw new \ValueError('imap_mail_move(): Argument #4 ($flags) must be CP_UID or 0');
        }

        return $this->copyTo($sequence, $folder, $options | CP_MOVE);
    }

    private function copyTo(string $sequence, string $folder, int $options): bool
    {
        $uidMode = UidMode::fromFlags($options, CP_UID);

        try {
            $this->connection->selectOrExamine();
            // Unlike APPEND and STATUS, c-client's COPY sends the mailbox
            // argument verbatim on the wire — a "{host}folder" spec is not
            // unwrapped and simply names a nonexistent folder server-side.
            $this->connection->backend()->copy($sequence, $folder, $uidMode);

            // c-client's CP_MOVE predates the IMAP MOVE extension: it marks
            // the source messages \Deleted after copying and leaves the
            // expunge to the caller.
            if ($options & CP_MOVE) {
                $command = ($options & CP_UID) ? 'UID STORE' : 'STORE';
                $this->connection->backend()->store($command, [$sequence, '+FLAGS.SILENT', '(\\Deleted)']);
            }
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }

    public function uid(int $messageNum): int|false
    {
        $this->connection->ensureOpen();

        if ($messageNum < 1) {
            throw new \ValueError('imap_uid(): Argument #2 ($message_num) must be greater than 0');
        }

        try {
            $status = $this->connection->selectOrExamine();

            if ($messageNum > $status->exists) {
                trigger_error('imap_uid(): Bad message number', E_USER_WARNING);

                return false;
            }

            $uids = $this->connection->backend()->getUid();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return (int) $uids[$messageNum];
    }

    public function msgno(int $messageUid): int
    {
        $this->connection->ensureOpen();

        if ($messageUid < 1) {
            throw new \ValueError('imap_msgno(): Argument #2 ($message_uid) must be greater than 0');
        }

        $status = $this->selectedFolder();

        if ($status === false || $status->exists < 1) {
            return 0;
        }

        try {
            return (int) $this->connection->backend()->getMessageNumber((string) $messageUid);
        } catch (MessageNotFoundException) {
            return 0;
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return 0;
        }
    }

    public function setFlagFull(string $sequence, string $flag, int $options): bool
    {
        $this->connection->ensureOpen();

        if (($options & ~ST_UID) !== 0) {
            throw new \ValueError('imap_setflag_full(): Argument #4 ($options) must be ST_UID or 0');
        }

        return $this->storeFlags($sequence, $flag, $options, '+FLAGS.SILENT');
    }

    public function clearFlagFull(string $sequence, string $flag, int $options): bool
    {
        $this->connection->ensureOpen();

        if (($options & ~ST_UID) !== 0) {
            throw new \ValueError('imap_clearflag_full(): Argument #4 ($options) must be ST_UID or 0');
        }

        return $this->storeFlags($sequence, $flag, $options, '-FLAGS.SILENT');
    }

    /**
     * Always true, whatever the server said: php_imap.c's
     * imap_setflag_full/imap_clearflag_full return RETURN_TRUE
     * unconditionally, having thrown away mail_setflag_full()'s void.
     */
    private function storeFlags(string $sequence, string $flag, int $options, string $item): bool
    {
        $command = ($options & ST_UID) ? 'UID STORE' : 'STORE';

        try {
            $this->connection->selectOrExamine();
            $this->connection->backend()->store($command, [$sequence, $item, '('.trim($flag).')']);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());
        }

        return true;
    }

    public function expunge(): bool
    {
        $this->connection->ensureOpen();

        try {
            $this->connection->selectOrExamine();
            $this->connection->backend()->expunge();
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());
        }

        return true;
    }

    public function append(string $folder, string $message, ?string $options, ?string $internalDate): bool
    {
        $this->connection->ensureOpen();

        $folderName = MailboxReference::parse($folder)->bareReference;
        $flags = $options !== null ? (preg_split('/\s+/', trim($options)) ?: []) : null;

        try {
            $this->connection->backend()->appendMessage($folderName, $message, $flags, $internalDate);
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @return string[]
     */
    public function headers(): array
    {
        $this->connection->ensureOpen();

        try {
            $status = $this->connection->selectOrExamine();
            $exists = $status->exists;

            if ($exists === 0) {
                return [];
            }

            $ids = range(1, $exists);
            $data = $this->connection->backend()->fetch(
                HeaderInfo::FETCH_ITEMS,
                $ids,
                null,
                UidMode::MSGNO,
            );
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return [];
        }

        $result = [];
        foreach ($ids as $msgno) {
            if (!isset($data[$msgno])) {
                continue;
            }

            $message = $data[$msgno];
            $result[] = HeadersLine::build(
                $message['RFC822.HEADER'],
                $message['FLAGS'],
                $message['INTERNALDATE'],
                (int) $message['RFC822.SIZE'],
                $msgno,
                $this->connection->backend()->host(),
                $this->connection->userFlags(),
            );
        }

        return $result;
    }

    /**
     * @return int[]|false
     */
    public function sort(int $criteria, bool $reverse, int $flags, ?string $searchCriteria, ?string $charset): array|false
    {
        $this->connection->ensureOpen();

        $criterion = SortCriterion::tryFromConstant($criteria)
            ?? throw new \ValueError('imap_sort(): Argument #2 ($criteria) must be one of the SORT* constants');

        if ($flags && ($flags & ~(SE_UID | SE_NOPREFETCH)) !== 0) {
            throw new \ValueError('imap_sort(): Argument #4 ($flags) must be a bitmask of SE_UID, and SE_NOPREFETCH');
        }

        // The sort's own search criteria go through the same mail_criteria()
        // as imap_search()'s, and a keyword it cannot build fails the sort
        // outright rather than sorting the whole folder instead.
        try {
            $program = $searchCriteria !== null ? SearchProgram::parse($searchCriteria) : null;
        } catch (\RuntimeException $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        try {
            $status = $this->connection->selectOrExamine();
            $exists = $status->exists;

            // c-client hands the whole sort to the server whenever it
            // advertises SORT, and only falls back to its own algorithms when
            // the server has none or rejects the command (imap4r1.c
            // imap_sort). An absent search program is the empty SEARCHPGM,
            // which serializes to ALL.
            if ($this->connection->backend()->hasCapability('SORT')) {
                $sorted = $this->connection->backend()->sort(
                    ($reverse ? 'REVERSE ' : '').SortKey::wireName($criterion),
                    $charset ?? 'US-ASCII',
                    $program === null ? ['ALL'] : $program->tokens,
                    UidMode::fromFlags($flags, SE_UID),
                );

                if ($sorted !== null) {
                    return $sorted;
                }
            }

            // Only the local algorithms need the count: an empty folder
            // has no range to walk. The server was still asked first, so a
            // connection that has gone is reported rather than passed over.
            if ($exists === 0) {
                return [];
            }

            if ($program !== null) {
                $ids = $this->connection->backend()->search($program, UidMode::MSGNO);

                if ($ids === []) {
                    return [];
                }
            } else {
                $ids = range(1, $exists);
            }

            $data = $this->connection->backend()->fetch(
                ['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'],
                $ids,
                null,
                UidMode::MSGNO,
            );
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            // php_imap.c fills an array from whatever mail_sort() gave it,
            // so a failed sort is an empty result rather than false.
            return [];
        }

        $host = $this->connection->backend()->host();
        $entries = [];
        foreach ($ids as $msgno) {
            if (!isset($data[$msgno])) {
                continue;
            }

            $message = $data[$msgno];
            $entries[] = [
                'msgno' => $msgno,
                'uid' => (int) $message['UID'],
                'key' => SortKey::resolve($criterion, $message, $host),
            ];
        }

        usort($entries, static function (array $a, array $b) use ($reverse): int {
            $cmp = $a['key'] <=> $b['key'];
            if ($cmp === 0) {
                $cmp = $a['msgno'] <=> $b['msgno'];
            }

            return $reverse ? -$cmp : $cmp;
        });

        $byUid = (bool) ($flags & SE_UID);

        return array_map(static fn (array $e): int => $byUid ? $e['uid'] : $e['msgno'], $entries);
    }

    /**
     * @return array<string, int>|false
     */
    public function thread(int $flags): array|false
    {
        $this->connection->ensureOpen();

        try {
            $status = $this->connection->selectOrExamine();
            $exists = $status->exists;

            if ($exists === 0) {
                return false;
            }

            // c-client threads on the server whenever it advertises the
            // algorithm asked for, and only falls back to its own REFERENCES
            // implementation otherwise (imap4r1.c imap_thread). php_imap.c
            // always asks for REFERENCES over the whole mailbox ("ALL").
            if ($this->connection->backend()->hasCapability('THREAD=REFERENCES')) {
                $byUid = (bool) ($flags & SE_UID);
                $groups = $this->connection->backend()->thread(
                    'REFERENCES',
                    'US-ASCII',
                    ['ALL'],
                    $byUid ? UidMode::UID : UidMode::MSGNO,
                );

                if ($groups !== null) {
                    $tree = ThreadBuilder::flatten(ThreadBuilder::containersFromServer($groups, $byUid), $byUid);

                    return $tree === [] ? false : $tree;
                }
            }

            $ids = range(1, $exists);
            $data = $this->connection->backend()->fetch(
                ['UID', 'INTERNALDATE', 'RFC822.HEADER'],
                $ids,
                null,
                UidMode::MSGNO,
            );
        } catch (\Throwable $e) {
            ErrorStack::push($e->getMessage());

            return false;
        }

        $messages = ThreadBuilder::messagesFromFetch($data, $ids);
        $root = ThreadBuilder::build($messages);
        $tree = ThreadBuilder::flatten($root, (bool) ($flags & SE_UID));

        if ($tree === []) {
            return false;
        }

        return $tree;
    }
}
