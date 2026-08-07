<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Address\AddressList;

final class HeaderInfo
{
    /** In the order php_imap.c writes them into the envelope object. */
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
        int $fromLength = 0,
        int $subjectLength = 0,
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
        $result->MailDate = InternalDate::padDay($internalDate);
        $result->Size = $size;
        $result->udate = strtotime($internalDate);

        // Like ext-imap: only present when a length was requested and the
        // envelope has the source field.
        if ($fromLength !== 0 && isset($result->from)) {
            $result->fetchfrom = self::fixedWidthFrom($result->from, $fromLength);
        }
        if ($subjectLength !== 0 && isset($result->subject)) {
            $result->fetchsubject = substr($result->subject, 0, $subjectLength);
        }

        return $result;
    }

    /**
     * c-client's mail_fetchfrom(): exactly $length characters, space-padded
     * — the first address's personal name if it has one, else
     * "mailbox@host" with each side capped at 256 characters.
     *
     * @param \stdClass[] $from
     */
    private static function fixedWidthFrom(array $from, int $length): string
    {
        $address = $from[0] ?? null;
        if ($address === null) {
            return str_repeat(' ', $length);
        }

        $text = $address->personal
            ?? sprintf('%s@%s', substr($address->mailbox, 0, 256), substr($address->host, 0, 256));

        return str_pad(substr($text, 0, $length), $length);
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

        // php_imap.c fills the envelope in this order, and the order is
        // observable: foreach, get_object_vars() and var_dump() all show it.
        // Note that each address list is preceded by its raw "*address"
        // string, not followed by it.
        if (isset($fields['date'])) {
            $result->date = $fields['date'];
            $result->Date = $fields['date'];
        }

        if (isset($fields['subject'])) {
            $result->subject = $fields['subject'];
            $result->Subject = $fields['subject'];
        }

        foreach (['in-reply-to' => 'in_reply_to', 'message-id' => 'message_id', 'references' => 'references'] as $header => $property) {
            if (isset($fields[$header])) {
                $result->$property = $fields[$header];
            }
        }

        foreach (self::ADDRESS_HEADERS as $header => $property) {
            if (!isset($fields[$header])) {
                continue;
            }

            $result->{$property.'address'} = $fields[$header];
            $result->$property = AddressList::parse($fields[$header], $defaultHost)->toLegacyArray();
        }

        // RFC 5322: Reply-To and Sender default to From when not explicitly set.
        foreach (['reply_to', 'sender'] as $property) {
            if (!isset($result->$property) && isset($result->from)) {
                $result->{$property.'address'} = $result->fromaddress;
                $result->$property = $result->from;
            }
        }

        return $result;
    }
}
