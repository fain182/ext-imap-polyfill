<?php

namespace ImapPolyfill\Message;

final class Overview
{
    /**
     * @param string[] $flags
     */
    public static function build(
        Envelope $envelope,
        array $flags,
        string $internalDate,
        int $size,
        int $uid,
        int $msgno,
    ): \stdClass {
        $result = new \stdClass();

        if ($envelope->subject !== null) {
            $result->subject = $envelope->subject;
        }

        if (($sender = $envelope->from()?->writeFirst()) !== null) {
            $result->from = $sender;
        }

        if (($recipient = $envelope->to()?->writeFirst()) !== null) {
            $result->to = $recipient;
        }

        if ($envelope->date !== null) {
            $result->date = $envelope->date;
        }

        if ($envelope->messageId !== null) {
            $result->message_id = $envelope->messageId;
        }

        if ($envelope->references !== null) {
            $result->references = $envelope->references;
        }

        if ($envelope->inReplyTo !== null) {
            $result->in_reply_to = $envelope->inReplyTo;
        }

        $result->size = $size;
        $result->uid = $uid;
        $result->msgno = $msgno;
        $result->recent = (int) in_array('\\Recent', $flags, true);
        $result->flagged = (int) in_array('\\Flagged', $flags, true);
        $result->answered = (int) in_array('\\Answered', $flags, true);
        $result->deleted = (int) in_array('\\Deleted', $flags, true);
        $result->seen = (int) in_array('\\Seen', $flags, true);
        $result->draft = (int) in_array('\\Draft', $flags, true);
        $result->udate = strtotime($internalDate);

        return $result;
    }
}
