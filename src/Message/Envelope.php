<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Address\AddressList;

/**
 * c-client's ENVELOPE: what imap_headerinfo(), imap_fetch_overview() and
 * imap_rfc822_parse_headers() are written from.
 *
 * It comes from two places, as in c-client. Over IMAP the server builds it:
 * imap_fetchenvelope asks for ENVELOPE and takes every field as the server
 * gave it — its idea of the addresses, its whitespace — with only References
 * read from the header text (BODY.PEEK[HEADER.FIELDS (...)]), since ENVELOPE
 * has no slot for it. Everywhere else — POP3, a header string handed to
 * imap_rfc822_parse_headers() — rfc822_parse_msg() reads it out of the
 * header itself.
 */
final class Envelope
{
    /**
     * In the order php_imap.c writes them.
     *
     * Return-Path is deliberately not one of them: _php_make_header_object()
     * would write the property, but rfc822_parse_msg_full()'s header dispatch
     * has no case for that header — its 'R' arm knows Reply-To and References
     * and nothing else — and an IMAP ENVELOPE has no slot for it, so
     * env->return_path is left NIL. The only place c-client ever fills it is
     * an envelope built by imap_mail_compose(), which is not this one.
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
     * @param array<string, AddressList> $addresses keyed by property name,
     *                                              only the lists c-client
     *                                              left non-NIL
     */
    private function __construct(
        public readonly ?string $date,
        public readonly ?string $subject,
        public readonly ?string $inReplyTo,
        public readonly ?string $messageId,
        public readonly ?string $references,
        private readonly array $addresses,
    ) {
    }

    /**
     * rfc822_parse_msg() over a header block. Address complaints go on the
     * error stack here, in the order the headers were written.
     */
    public static function fromHeader(string $rawHeader, string $defaultHost): self
    {
        $fields = RawHeaderFields::parse($rawHeader);

        // Parsed where each header stands: c-client's dispatch reaches the
        // headers in the order the message wrote them, and two malformed
        // ones put their complaints on the error stack in that order. A
        // header written twice is parsed twice and appended.
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

        // A header holding nothing an address parser can use leaves the list
        // NIL, as if the header were not there.
        $parsed = self::withoutEmptyLists($parsed);

        // rfc822_parse_msg() copies From into a Reply-To or Sender it left
        // NIL, inside the envelope.
        if (isset($parsed['from'])) {
            $parsed['reply_to'] ??= $parsed['from'];
            $parsed['sender'] ??= $parsed['from'];
        }

        // Each text field is written once and kept: a second Date header is
        // read and dropped, since c-client only fills a field it has not
        // filled yet.
        return new self(
            $fields->first('date'),
            $fields->first('subject'),
            $fields->first('in-reply-to'),
            $fields->first('message-id'),
            $fields->first('references'),
            $parsed,
        );
    }

    /**
     * imap_parse_envelope() over a FETCH ENVELOPE answer — (date subject
     * from sender reply-to to cc bcc in-reply-to message-id) — and the
     * header text of the References field fetched beside it. Nothing is
     * defaulted or cleaned up: a NIL Reply-To stays NIL, a value with the
     * server's leading space keeps it.
     *
     * @param list<mixed>|null $envelope
     */
    public static function fromImap(?array $envelope, string $referencesHeader): self
    {
        $text = static fn (int $index): ?string => isset($envelope[$index]) ? (string) $envelope[$index] : null;

        $addresses = [];

        foreach (['from' => 2, 'sender' => 3, 'reply_to' => 4, 'to' => 5, 'cc' => 6, 'bcc' => 7] as $property => $index) {
            if (is_array($envelope[$index] ?? null)) {
                $addresses[$property] = AddressList::fromEnvelope($envelope[$index]);
            }
        }

        return new self(
            $text(0),
            $text(1),
            $text(8),
            $text(9),
            RawHeaderFields::parse($referencesHeader)->first('references'),
            self::withoutEmptyLists($addresses),
        );
    }

    /**
     * @param array<string, AddressList> $lists
     *
     * @return array<string, AddressList>
     */
    private static function withoutEmptyLists(array $lists): array
    {
        return array_filter($lists, static fn (AddressList $list): bool => !$list->isEmpty());
    }

    /** What mail_fetchfrom() reads: the parsed list, not the legacy objects. */
    public function from(): ?AddressList
    {
        return $this->addresses['from'] ?? null;
    }

    public function to(): ?AddressList
    {
        return $this->addresses['to'] ?? null;
    }

    public function cc(): ?AddressList
    {
        return $this->addresses['cc'] ?? null;
    }

    /**
     * _php_make_header_object(): every field in php_imap.c's order, each
     * address list preceded by its "*address" string rather than followed
     * by it. The order is observable: foreach, get_object_vars() and
     * var_dump() all show it.
     */
    public function toObject(): \stdClass
    {
        $result = new \stdClass();

        if ($this->date !== null) {
            $result->date = $this->date;
            $result->Date = $this->date;
        }

        if ($this->subject !== null) {
            $result->subject = $this->subject;
            $result->Subject = $this->subject;
        }

        if ($this->inReplyTo !== null) {
            $result->in_reply_to = $this->inReplyTo;
        }

        if ($this->messageId !== null) {
            $result->message_id = $this->messageId;
        }

        if ($this->references !== null) {
            $result->references = $this->references;
        }

        foreach (self::ADDRESS_HEADERS as $property) {
            $addresses = $this->addresses[$property] ?? null;

            if ($addresses === null) {
                continue;
            }

            // Not the header text: the "*address" string is written back out
            // of the list, so it carries the default host, the markers and
            // the quoting c-client put there rather than what was read.
            $result->{$property.'address'} = $addresses->write();
            $result->$property = $addresses->toLegacyArray();
        }

        return $result;
    }
}
