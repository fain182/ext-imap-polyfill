<?php

namespace ImapPolyfill\Message;

final class HeaderInfo
{
    /**
     * @param string[] $flags
     */
    public static function build(
        Envelope $envelope,
        array $flags,
        string $internalDate,
        string $size,
        int $msgno,
        int $fromLength = 0,
        int $subjectLength = 0,
    ): \stdClass {
        $result = $envelope->toObject();

        $result->Recent = in_array('\\Recent', $flags, true)
            ? (in_array('\\Seen', $flags, true) ? 'R' : 'N')
            : ' ';
        $result->Unseen = (in_array('\\Recent', $flags, true) || in_array('\\Seen', $flags, true)) ? ' ' : 'U';
        $result->Flagged = in_array('\\Flagged', $flags, true) ? 'F' : ' ';
        $result->Answered = in_array('\\Answered', $flags, true) ? 'A' : ' ';
        $result->Deleted = in_array('\\Deleted', $flags, true) ? 'D' : ' ';
        $result->Draft = in_array('\\Draft', $flags, true) ? 'X' : ' ';

        $result->Msgno = sprintf('%4d', $msgno);
        $result->MailDate = InternalDate::padDay($internalDate);
        $result->Size = $size;
        $result->udate = strtotime($internalDate);

        // Like ext-imap: only present when a length was requested and the
        // envelope has the source field.
        if ($fromLength !== 0 && ($from = $envelope->from()) !== null) {
            $result->fetchfrom = $from->fetchFrom($fromLength);
        }
        if ($subjectLength !== 0 && $envelope->subject !== null) {
            $result->fetchsubject = substr($envelope->subject, 0, $subjectLength);
        }

        return $result;
    }
}
