<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Address\AddressList;

final class HeaderInfo
{
    /**
     * In the order php_imap.c writes them into the envelope object.
     *
     * Return-Path is deliberately not one of them: _php_make_header_object()
     * would write the property, but rfc822_parse_msg_full()'s header dispatch
     * has no case for that header — its 'R' arm knows Reply-To and References
     * and nothing else — so env->return_path is left NIL for anything parsed
     * out of header text. The only place c-client ever fills it is an envelope
     * built by imap_mail_compose(), which is not this one.
     */
    private const ADDRESS_HEADERS = [
        'to' => 'to',
        'from' => 'from',
        'cc' => 'cc',
        'bcc' => 'bcc',
        'reply-to' => 'reply_to',
        'sender' => 'sender',
    ];

    /**
     * The FETCH items build() needs, in one place because two callers ask
     * for them — imap_headerinfo() for one message, imap_sort()'s local
     * fallback for the whole folder — and a field added here has to be
     * added to the request or it will not be there to read.
     *
     * @var list<string>
     */
    public const FETCH_ITEMS = ['FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'];

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
        // Each of these is written once and kept: a second Date header is
        // read and dropped, since c-client only fills a field it has not
        // filled yet.
        if (($date = $fields->first('date')) !== null) {
            $result->date = $date;
            $result->Date = $date;
        }

        if (($subject = $fields->first('subject')) !== null) {
            $result->subject = $subject;
            $result->Subject = $subject;
        }

        foreach (['in-reply-to' => 'in_reply_to', 'message-id' => 'message_id', 'references' => 'references'] as $header => $property) {
            if (($value = $fields->first($header)) !== null) {
                $result->$property = $value;
            }
        }

        // Parsed where each header stands, which is not the order the
        // properties are written in below: c-client's dispatch reaches the
        // headers in the order the message wrote them, and two malformed
        // ones put their complaints on the error stack in that order.
        $parsed = [];

        foreach ($fields->lines() as [$header, $value]) {
            $property = self::ADDRESS_HEADERS[$header] ?? null;

            if ($property === null) {
                continue;
            }

            $addresses = AddressList::parse($value, $defaultHost);
            $parsed[$property] = isset($parsed[$property])
                ? $parsed[$property]->append($addresses)
                : $addresses;
        }

        foreach (self::ADDRESS_HEADERS as $property) {
            $addresses = $parsed[$property] ?? null;

            // Both properties are guarded on the parsed list, not on the
            // header being there: php_imap.c's UPDATE_PROPERTY_PARSED_ADDRESS
            // tests en->to and friends, which a header holding nothing an
            // address parser can use leaves NIL.
            if ($addresses === null || $addresses->isEmpty()) {
                continue;
            }

            // Not the header text: the "*address" string is written back out
            // of what was parsed, so it carries the default host, the markers
            // and the quoting c-client put there rather than what was read.
            $result->{$property.'address'} = $addresses->write();
            $result->$property = $addresses->toLegacyArray();
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
