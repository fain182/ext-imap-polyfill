<?php

namespace ImapPolyfill;

final class Overview
{
    /**
     * @param string[] $flags
     */
    public static function build(
        string $rawHeader,
        array $flags,
        string $internalDate,
        int $size,
        int $uid,
        int $msgno,
        string $defaultHost,
    ): \stdClass {
        $fields = RawHeaderFields::parse($rawHeader);
        $result = new \stdClass();

        if (isset($fields['subject'])) {
            $result->subject = $fields['subject'];
        }

        if (isset($fields['from'])) {
            $result->from = AddressList::firstAsString($fields['from'], $defaultHost);
        }

        if (isset($fields['to'])) {
            $result->to = AddressList::firstAsString($fields['to'], $defaultHost);
        }

        if (isset($fields['date'])) {
            $result->date = $fields['date'];
        }

        if (isset($fields['message-id'])) {
            $result->message_id = $fields['message-id'];
        }

        if (isset($fields['references'])) {
            $result->references = $fields['references'];
        }

        if (isset($fields['in-reply-to'])) {
            $result->in_reply_to = $fields['in-reply-to'];
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
