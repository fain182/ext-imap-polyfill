<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Address\AddressList;

final class HeaderInfo
{
    private const ADDRESS_HEADERS = [
        'to' => 'to',
        'from' => 'from',
        'cc' => 'cc',
        'bcc' => 'bcc',
        'reply-to' => 'reply_to',
        'sender' => 'sender',
        'return-path' => 'return_path',
    ];

    /**
     * @param string[] $flags
     */
    public static function build(
        string $rawHeader,
        array $flags,
        string $internalDate,
        string $size,
        int $msgno,
        string $defaultHost,
    ): \stdClass {
        $result = self::buildFromHeaderOnly($rawHeader, $defaultHost);

        $result->Recent = in_array('\\Recent', $flags, true)
            ? (in_array('\\Seen', $flags, true) ? 'R' : 'N')
            : ' ';
        $result->Unseen = (in_array('\\Recent', $flags, true) || in_array('\\Seen', $flags, true)) ? ' ' : 'U';
        $result->Flagged = in_array('\\Flagged', $flags, true) ? 'F' : ' ';
        $result->Answered = in_array('\\Answered', $flags, true) ? 'A' : ' ';
        $result->Deleted = in_array('\\Deleted', $flags, true) ? 'D' : ' ';
        $result->Draft = in_array('\\Draft', $flags, true) ? 'X' : ' ';

        $result->Msgno = sprintf('%4d', $msgno);
        $result->MailDate = $internalDate;
        $result->Size = $size;
        $result->udate = strtotime($internalDate);

        return $result;
    }

    /**
     * The subset shared with imap_rfc822_parse_headers(): header-derived
     * fields only, none of the connection/message-state properties
     * (Recent/Unseen/.../Msgno/MailDate/Size/udate) a standalone header
     * string has no data for.
     */
    public static function buildFromHeaderOnly(string $rawHeader, string $defaultHost): \stdClass
    {
        $fields = RawHeaderFields::parse($rawHeader);
        $result = new \stdClass();

        foreach (self::ADDRESS_HEADERS as $header => $property) {
            if (!isset($fields[$header])) {
                continue;
            }

            $result->$property = AddressList::parse($fields[$header], $defaultHost)->toLegacyArray();
            $result->{$property.'address'} = $fields[$header];
        }

        // RFC 5322: Reply-To and Sender default to From when not explicitly set.
        foreach (['reply_to', 'sender'] as $property) {
            if (!isset($result->$property) && isset($result->from)) {
                $result->$property = $result->from;
                $result->{$property.'address'} = $result->fromaddress;
            }
        }

        foreach (['message-id' => 'message_id', 'in-reply-to' => 'in_reply_to', 'references' => 'references'] as $header => $property) {
            if (isset($fields[$header])) {
                $result->$property = $fields[$header];
            }
        }

        if (isset($fields['date'])) {
            $result->date = $fields['date'];
            $result->Date = $fields['date'];
        }

        if (isset($fields['subject'])) {
            $result->subject = $fields['subject'];
            $result->Subject = $fields['subject'];
        }

        return $result;
    }
}
