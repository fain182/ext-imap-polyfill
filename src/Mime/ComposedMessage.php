<?php

namespace ImapPolyfill\Mime;

use ImapPolyfill\Address\AddressList;
use ImapPolyfill\Support\ErrorStack;

/**
 * The body of imap_mail_compose(): builds a MIME message string from the
 * envelope/bodies array contract of ext-imap, replicating php_imap.c's
 * PHP_FUNCTION(imap_mail_compose) plus the c-client routines it drives
 * (rfc822_encode_body_7bit, rfc822_output_header, rfc822_output_body_header,
 * rfc822_output_address_list, rfc822_output_cat) — including their quirks,
 * which are load-bearing for parity and flagged inline where replicated.
 */
final class ComposedMessage
{
    private const CRLF = "\r\n";

    /** c-client parses "adr <adr@host>" address input against this default host. */
    private const DEFAULT_HOST = 'NO HOST';

    /** Indexes are the TYPE* constants; codes 9..TYPEMAX(15) have no name and are rejected. */
    private const TYPE_NAMES = [
        'TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO',
        'MODEL', 'X-UNKNOWN',
    ];

    /** Indexes are the ENC* constants; codes 6..ENCMAX(10) have no name and are rejected. */
    private const ENCODING_NAMES = [
        '7BIT', '8BIT', 'BINARY', 'BASE64', 'QUOTED-PRINTABLE', 'X-UNKNOWN',
    ];

    private const CONTROL_CHARS = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /** c-client rspecials: characters forcing a personal phrase into a quoted string. */
    private const RSPECIALS = "()<>@,;:\\\"[].".self::CONTROL_CHARS;

    /** c-client wspecials: dot-atom specials (dot handled separately). */
    private const WSPECIALS = " ()<>@,;:\\\"[]".self::CONTROL_CHARS;

    /** c-client tspecials: characters forcing a MIME parameter value into a quoted string. */
    private const TSPECIALS = " ()<>@,;:\\\"[]/?=".self::CONTROL_CHARS;

    /** php_imap.c: SENDBUFLEN(16385) - 2 - 2 - 2, despite the "4kb" warning text. */
    private const MAX_BOUNDARY_LENGTH = 16379;

    /**
     * @param array<array-key, mixed> $envelope
     * @param array<array-key, mixed> $bodies
     */
    public static function compose(array $envelope, array $bodies): string|false
    {
        if ($bodies === []) {
            throw new \ValueError('imap_mail_compose(): Argument #2 ($bodies) cannot be empty');
        }

        try {
            return self::render($envelope, $bodies);
        } catch (HeaderInjection $e) {
            trigger_error("imap_mail_compose(): header injection attempt in {$e->field}", E_USER_WARNING);

            return false;
        }
    }

    /**
     * @param array<array-key, mixed> $envelope
     * @param non-empty-array<array-key, mixed> $bodies
     */
    private static function render(array $envelope, array $bodies): string|false
    {
        $remail = self::stringField($envelope, 'remail', 'remail');
        // Parsed and injection-checked like ext-imap, but rfc822_output_header
        // never writes a Return-Path line, so the value goes nowhere.
        self::addressField($envelope, 'return_path');
        $date = self::stringField($envelope, 'date', 'date');
        $from = self::addressField($envelope, 'from');
        $replyTo = self::addressField($envelope, 'reply_to');
        $inReplyTo = self::stringField($envelope, 'in_reply_to', 'in_reply_to');
        $subject = self::stringField($envelope, 'subject', 'subject');
        $to = self::addressField($envelope, 'to');
        $cc = self::addressField($envelope, 'cc');
        $bcc = self::addressField($envelope, 'bcc');
        $messageId = self::stringField($envelope, 'message_id', 'message_id');
        $customHeaders = self::customHeaders($envelope);

        $topBody = null;
        $lastBody = null;
        foreach ($bodies as $spec) {
            if ($topBody === null) {
                if (!is_array($spec)) {
                    throw new \TypeError(sprintf(
                        'imap_mail_compose(): Argument #2 ($bodies) individual body must be of type array, %s given',
                        self::valueName($spec)
                    ));
                }
                if ($spec === []) {
                    throw new \ValueError('imap_mail_compose(): Argument #2 ($bodies) individual body cannot be empty');
                }
                $topBody = $lastBody = self::parseBody($spec, isFirst: true);
            } elseif (is_array($spec) && $topBody->type === TYPEMULTIPART) {
                $part = self::parseBody($spec, isFirst: false);
                $topBody->parts[] = $part;
                $lastBody = $part;
            }
        }

        // php_imap.c runs this check on the *last* body it processed, not the
        // top one, so a multipart with a single component slips through and
        // only the zero-component case actually fails.
        if ($lastBody->type === TYPEMULTIPART && count($lastBody->parts) < 2) {
            trigger_error('imap_mail_compose(): Cannot generate multipart e-mail without components.', E_USER_WARNING);

            return false;
        }

        self::encodeBody7bit($topBody);

        $header = self::renderEnvelopeHeader(
            $remail, $date, $from, $replyTo, $subject, $to, $cc, $bcc, $inReplyTo, $messageId, $topBody
        );

        if ($customHeaders !== []) {
            // ext-imap collects custom headers by prepending to a linked list,
            // so they come out in reverse array order, spliced in before the
            // header's terminating blank line.
            $header = substr($header, 0, -2);
            foreach (array_reverse($customHeaders) as $customHeader) {
                $header .= $customHeader.self::CRLF;
            }
            $header .= self::CRLF;
        }

        if ($topBody->type !== TYPEMULTIPART) {
            return $header.$topBody->contents.self::CRLF;
        }

        $cookie = null;
        foreach ($topBody->parameters as [$attribute, $value]) {
            if ($attribute === 'BOUNDARY') {
                $cookie = $value;
                break;
            }
        }
        if ($cookie === null) {
            $cookie = '-'; // c-client's "yucky default"; unreachable in practice, encodeBody7bit() always plants one
        } elseif (strlen($cookie) > self::MAX_BOUNDARY_LENGTH) {
            trigger_error('imap_mail_compose(): The boundary should be no longer than 4kb', E_USER_WARNING);

            return false;
        }

        $message = $header;
        foreach ($topBody->parts as $part) {
            $message .= '--'.$cookie.self::CRLF.self::renderBodyHeader($part).self::CRLF.$part->contents.self::CRLF;
        }

        return $message.'--'.$cookie.'--'.self::CRLF;
    }

    /**
     * @param array<array-key, mixed> $envelope
     */
    private static function stringField(array $envelope, string $key, string $field): ?string
    {
        if (!array_key_exists($key, $envelope)) {
            return null;
        }

        $value = self::stringValue($envelope[$key]);
        self::checkInjection($value, adrlist: false, field: $field);

        return $value;
    }

    /**
     * @param array<array-key, mixed> $envelope
     *
     * @return \stdClass[]
     */
    private static function addressField(array $envelope, string $key): array
    {
        if (!array_key_exists($key, $envelope)) {
            return [];
        }

        $value = self::stringValue($envelope[$key]);
        self::checkInjection($value, adrlist: true, field: $key);

        return AddressList::parse($value, self::DEFAULT_HOST)->toLegacyArray();
    }

    /**
     * @param array<array-key, mixed> $envelope
     *
     * @return string[]
     */
    private static function customHeaders(array $envelope): array
    {
        if (!isset($envelope['custom_headers']) || !is_array($envelope['custom_headers'])) {
            return [];
        }

        $headers = [];
        foreach ($envelope['custom_headers'] as $header) {
            $header = self::stringValue($header);
            self::checkInjection($header, adrlist: false, field: 'custom_headers');
            $headers[] = $header;
        }

        return $headers;
    }

    /**
     * @param array<array-key, mixed> $spec
     */
    private static function parseBody(array $spec, bool $isFirst): ComposedBody
    {
        $body = new ComposedBody();

        if (array_key_exists('type', $spec)) {
            $type = (int) $spec['type'];
            if (isset(self::TYPE_NAMES[$type]) && ($isFirst || $type !== TYPEMULTIPART)) {
                $body->type = $type;
            }
        }

        if (array_key_exists('encoding', $spec)) {
            $encoding = (int) $spec['encoding'];
            if (isset(self::ENCODING_NAMES[$encoding])) {
                $body->encoding = $encoding;
            }
        }

        if (array_key_exists('charset', $spec)) {
            $charset = self::stringValue($spec['charset']);
            self::checkInjection($charset, adrlist: false, field: 'body charset');
            $body->parameters = [['CHARSET', $charset], ...$body->parameters];
        }

        // php_imap.c swaps the injection-warning labels between
        // "type.parameters" and "disposition" for the first body only.
        $typeParametersLabel = $isFirst ? 'body disposition' : 'body type.parameters';
        $dispositionLabel = $isFirst ? 'body type.parameters' : 'body disposition';

        if (array_key_exists('type.parameters', $spec)) {
            $parameters = self::parseParameters($spec['type.parameters'], $typeParametersLabel);
            if ($parameters !== null) {
                // Replaces the whole list: a charset given alongside
                // type.parameters is discarded, like in ext-imap.
                $body->parameters = $parameters;
            }
        }

        if (array_key_exists('subtype', $spec)) {
            $subtype = self::stringValue($spec['subtype']);
            self::checkInjection($subtype, adrlist: false, field: 'body subtype');
            $body->subtype = $subtype;
        }

        if (array_key_exists('id', $spec)) {
            $id = self::stringValue($spec['id']);
            self::checkInjection($id, adrlist: false, field: 'body id');
            $body->id = $id;
        }

        if (array_key_exists('description', $spec)) {
            $description = self::stringValue($spec['description']);
            self::checkInjection($description, adrlist: false, field: 'body description');
            $body->description = $description;
        }

        if (array_key_exists('disposition.type', $spec)) {
            $dispositionType = self::stringValue($spec['disposition.type']);
            self::checkInjection($dispositionType, adrlist: false, field: 'body disposition.type');
            $body->dispositionType = $dispositionType;
        }

        if (array_key_exists('disposition', $spec)) {
            $parameters = self::parseParameters($spec['disposition'], $dispositionLabel);
            if ($parameters !== null) {
                $body->dispositionParameters = $parameters;
            }
        }

        // Exact-case comparison like c-client: only MESSAGE/RFC822 becomes a
        // nested (empty) message; MESSAGE/rfc822 reads contents.data normally.
        if (!($body->type === TYPEMESSAGE && $body->subtype === 'RFC822')
            && array_key_exists('contents.data', $spec)) {
            $body->contents = self::stringValue($spec['contents.data']);
        }

        if (array_key_exists('md5', $spec)) {
            $md5 = self::stringValue($spec['md5']);
            self::checkInjection($md5, adrlist: false, field: 'body md5');
            $body->md5 = $md5;
        }

        return $body;
    }

    /**
     * Returns null when the input isn't an attribute-keyed map, in which case
     * the caller must leave the existing parameter list untouched.
     *
     * @return list<array{string, string}>|null
     */
    private static function parseParameters(mixed $value, string $label): ?array
    {
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }

        $parameters = [];
        foreach ($value as $attribute => $parameterValue) {
            if (is_int($attribute)) {
                continue;
            }
            self::checkInjection($attribute, adrlist: false, field: $label.' key');
            $parameterValue = self::stringValue($parameterValue);
            self::checkInjection($parameterValue, adrlist: false, field: $label.' value');
            // Prepend like ext-imap's linked list: output order is the
            // reverse of the array order.
            array_unshift($parameters, [$attribute, $parameterValue]);
        }

        return $parameters;
    }

    private static function encodeBody7bit(ComposedBody $body): void
    {
        if ($body->type === TYPEMULTIPART) {
            $hasBoundary = false;
            foreach ($body->parameters as [$attribute, $value]) {
                if ($attribute === 'BOUNDARY') { // case-sensitive, like c-client's strcmp
                    $hasBoundary = true;
                    break;
                }
            }
            if (!$hasBoundary) {
                $body->parameters[] = ['BOUNDARY', self::generateBoundary()];
            }
            foreach ($body->parts as $part) {
                self::encodeBody7bit($part);
            }

            return;
        }

        if ($body->type === TYPEMESSAGE) {
            // c-client can't re-encode an embedded message; it logs and sends as-is.
            if ($body->encoding === ENC8BIT) {
                ErrorStack::push('8-bit included message in 7-bit message body');
            } elseif ($body->encoding === ENCBINARY) {
                ErrorStack::push('Binary included message in 7-bit message body');
            }

            return;
        }

        if ($body->encoding === ENC8BIT) {
            $body->contents = quoted_printable_encode($body->contents);
            $body->encoding = ENCQUOTEDPRINTABLE;
        } elseif ($body->encoding === ENCBINARY) {
            $body->contents = Base64Text::encode($body->contents);
            $body->encoding = ENCBASE64;
        }
    }

    /** Same shape as c-client's "hostid-random-time=:pid" multipart cookie. */
    private static function generateBoundary(): string
    {
        return sprintf(
            '%u-%u-%u=:%u',
            crc32((string) php_uname('n')),
            random_int(0, 2147483647),
            time(),
            (int) getmypid()
        );
    }

    /**
     * @param \stdClass[] $from
     * @param \stdClass[] $replyTo
     * @param \stdClass[] $to
     * @param \stdClass[] $cc
     * @param \stdClass[] $bcc
     */
    private static function renderEnvelopeHeader(
        ?string $remail,
        ?string $date,
        array $from,
        array $replyTo,
        ?string $subject,
        array $to,
        array $cc,
        array $bcc,
        ?string $inReplyTo,
        ?string $messageId,
        ComposedBody $topBody,
    ): string {
        $resent = $remail !== null;

        $header = '';
        if ($remail !== null) {
            // Snip the remail header's own terminating blank line, keeping
            // one CRLF, like rfc822_output_header does.
            if (strlen($remail) > 4 && $remail[strlen($remail) - 4] === "\r") {
                $remail = substr($remail, 0, -2);
            }
            $header .= $remail;
        }

        $header .= self::headerLine('Date', $date, $resent);
        $header .= self::addressLine('From', $from, $resent);
        $header .= self::addressLine('Reply-To', $replyTo, $resent);
        $header .= self::headerLine('Subject', $subject, $resent);
        if ($bcc !== [] && $to === [] && $cc === []) {
            $header .= 'To: undisclosed recipients: ;'.self::CRLF;
        }
        $header .= self::addressLine('To', $to, $resent);
        // Lowercase "cc" is c-client's own spelling.
        $header .= self::addressLine('cc', $cc, $resent);
        $header .= self::headerLine('In-Reply-To', $inReplyTo, $resent);
        $header .= self::headerLine('Message-ID', $messageId, $resent);

        if (!$resent) {
            $header .= 'MIME-Version: 1.0'.self::CRLF.self::renderBodyHeader($topBody);
        }

        return $header.self::CRLF;
    }

    private static function headerLine(string $name, ?string $text, bool $resent): string
    {
        if ($text === null) {
            return '';
        }

        return ($resent ? 'ReSent-' : '').$name.': '.$text.self::CRLF;
    }

    /**
     * rfc822_output_address_list: comma-separated addresses, folded onto a
     * 4-space continuation line once the running length reaches 78 — the
     * separator lands before the fold, so folded lines end with ", ".
     *
     * @param \stdClass[] $addresses
     */
    private static function addressLine(string $name, array $addresses, bool $resent): string
    {
        if ($addresses === []) {
            return '';
        }

        $line = ($resent ? 'ReSent-' : '').$name.': ';
        $lineLength = strlen($name) + ($resent ? strlen('ReSent-') : 0);
        $lastIndex = count($addresses) - 1;
        foreach (array_values($addresses) as $i => $address) {
            $chunk = self::renderAddress($address);
            if ($i < $lastIndex) {
                $chunk .= ', ';
            }
            $line .= $chunk;
            if ($i < $lastIndex) {
                $lineLength += strlen($chunk);
                if ($lineLength >= 78) {
                    $line .= self::CRLF.'    ';
                    $lineLength = 4;
                }
            }
        }

        return $line.self::CRLF;
    }

    private static function renderAddress(\stdClass $address): string
    {
        $route = self::cat($address->mailbox, null);
        if (!str_starts_with($address->host, '@')) {
            $route .= '@'.self::cat($address->host, null);
        }

        $personal = $address->personal ?? '';
        if ($personal !== '') {
            return self::cat($personal, self::RSPECIALS).' <'.$route.'>';
        }

        return $route;
    }

    private static function renderBodyHeader(ComposedBody $body): string
    {
        $header = 'Content-Type: '.self::TYPE_NAMES[$body->type]
            .'/'.($body->subtype ?? self::defaultSubtype($body->type));

        if ($body->parameters !== []) {
            foreach ($body->parameters as [$attribute, $value]) {
                $header .= '; '.$attribute.'='.self::cat($value, self::TSPECIALS);
            }
        } elseif ($body->type === TYPETEXT) {
            $header .= '; CHARSET='.($body->encoding === ENC7BIT ? 'US-ASCII' : 'X-UNKNOWN');
        }

        if ($body->encoding !== ENC7BIT) {
            $header .= self::CRLF.'Content-Transfer-Encoding: '.self::ENCODING_NAMES[$body->encoding];
        }
        if ($body->id !== null) {
            $header .= self::CRLF.'Content-ID: '.$body->id;
        }
        if ($body->description !== null) {
            $header .= self::CRLF.'Content-Description: '.$body->description;
        }
        if ($body->md5 !== null) {
            $header .= self::CRLF.'Content-MD5: '.$body->md5;
        }
        if ($body->dispositionType !== null) {
            $header .= self::CRLF.'Content-Disposition: '.$body->dispositionType;
            foreach ($body->dispositionParameters as [$attribute, $value]) {
                $header .= '; '.$attribute.'='.self::cat($value, self::TSPECIALS);
            }
        }

        return $header.self::CRLF;
    }

    private static function defaultSubtype(int $type): string
    {
        return match ($type) {
            TYPETEXT => 'PLAIN',
            TYPEMULTIPART => 'MIXED',
            TYPEMESSAGE => 'RFC822',
            TYPEAPPLICATION => 'OCTET-STREAM',
            TYPEAUDIO => 'BASIC',
            default => 'UNKNOWN',
        };
    }

    /**
     * rfc822_output_cat: emit verbatim unless quoting is needed — for a given
     * specials list when any special occurs, or in dot-atom mode (null) on
     * wspecials plus leading/doubled/trailing dots. Only backslash and
     * double-quote get escaped inside the quoted form.
     */
    private static function cat(string $value, ?string $specials): string
    {
        $needsQuoting = $value === ''
            || ($specials !== null
                ? strpbrk($value, $specials) !== false
                : (strpbrk($value, self::WSPECIALS) !== false
                    || str_starts_with($value, '.')
                    || str_contains($value, '..')
                    || str_ends_with($value, '.')));

        if (!$needsQuoting) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * php_imap.c's header_injection(): a CR or LF is legal only as part of a
     * CRLF pair, as a fold (line break followed by space/tab) in non-address
     * headers, or as a single trailing break in address headers.
     */
    private static function checkInjection(string $value, bool $adrlist, string $field): void
    {
        $length = strlen($value);
        $i = 0;
        while (true) {
            $i += strcspn($value, "\r\n", $i);
            if ($i >= $length) {
                return;
            }

            $next = $value[$i + 1] ?? '';
            $isCrlfPair = $value[$i] === "\r" && $next === "\n";
            $isAllowedBreak = $adrlist
                ? $i === $length - 1
                : ($next === ' ' || $next === "\t");

            if (!$isCrlfPair && !$isAllowedBreak) {
                throw new HeaderInjection($field);
            }
            $i++;
        }
    }

    private static function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            trigger_error('Array to string conversion', E_USER_WARNING);

            return 'Array';
        }

        // Mirrors convert_to_string(): objects without __toString throw,
        // exactly like ext-imap.
        return (string) $value;
    }

    private static function valueName(mixed $value): string
    {
        // Matches zend_zval_value_name(): booleans read as their value.
        return match ($value) {
            true => 'true',
            false => 'false',
            default => get_debug_type($value),
        };
    }
}
