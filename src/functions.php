<?php

if (!function_exists('imap_utf8')) {
    function imap_utf8(string $mime_encoded_text): string
    {
        return \ImapPolyfill\Mime\MimeText::decode($mime_encoded_text);
    }
}

if (!function_exists('imap_rfc822_parse_adrlist')) {
    function imap_rfc822_parse_adrlist(string $string, string $default_hostname): array
    {
        return \ImapPolyfill\Address\AddressList::parse($string, $default_hostname);
    }
}

if (!function_exists('imap_open')) {
    function imap_open(string $mailbox, string $user, string $password, int $flags = 0, int $retries = 0, array $options = []): \IMAP\Connection|false
    {
        $spec = \ImapPolyfill\Mailbox\MailboxSpec::parse($mailbox);

        $encryption = false;
        if ($spec->hasFlag('ssl')) {
            $encryption = 'ssl';
        } elseif ($spec->hasFlag('tls')) {
            $encryption = 'tls';
        }

        $client = (new \Webklex\PHPIMAP\ClientManager())->make([
            'host' => $spec->host,
            'port' => $spec->port,
            'encryption' => $encryption,
            'validate_cert' => !$spec->hasFlag('novalidate-cert'),
            'username' => $user,
            'password' => $password,
            'protocol' => 'imap',
        ]);

        $readOnly = (bool) ($flags & OP_READONLY);
        $attempts = 1 + max(0, $retries);

        $numMsg = 0;
        $numRecent = 0;
        $connected = false;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client->connect();
                $connected = true;
                break;
            } catch (\Throwable $e) {
                \ImapPolyfill\Support\ErrorStack::push($e->getMessage());
            }
        }

        if (!$connected) {
            trigger_error("imap_open(): Couldn't open stream {$mailbox}", E_USER_WARNING);

            return false;
        }

        try {
            if ($spec->folder !== '') {
                $folder = $client->getFolder($spec->folder);
                $status = $readOnly ? $folder->examine() : $folder->select();
                $numMsg = $status['exists'] ?? 0;
                $numRecent = $status['recent'] ?? 0;
            }
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());
            trigger_error("imap_open(): Couldn't open stream {$mailbox}", E_USER_WARNING);

            return false;
        }

        $connection = new \IMAP\Connection($client, $spec->folder, $mailbox, $readOnly);
        $connection->cachedNumMsg = $numMsg;
        $connection->cachedNumRecent = $numRecent;

        return $connection;
    }
}

if (!function_exists('imap_close')) {
    function imap_close(\IMAP\Connection $imap, int $flags = 0): bool
    {
        $imap->ensureOpen();

        if ($flags & CL_EXPUNGE) {
            $imap->client->expunge();
        }

        $imap->client->disconnect();
        $imap->closed = true;

        return true;
    }
}

if (!function_exists('imap_timeout')) {
    function imap_timeout(int $timeout_type, int $timeout = -1): int|bool
    {
        if ($timeout === -1) {
            return \ImapPolyfill\Support\Timeouts::get($timeout_type);
        }

        return \ImapPolyfill\Support\Timeouts::set($timeout_type, $timeout);
    }
}

if (!function_exists('imap_last_error')) {
    function imap_last_error(): string|false
    {
        return \ImapPolyfill\Support\ErrorStack::last();
    }
}

if (!function_exists('imap_errors')) {
    function imap_errors(): array|false
    {
        return \ImapPolyfill\Support\ErrorStack::drainErrors();
    }
}

if (!function_exists('imap_alerts')) {
    function imap_alerts(): array|false
    {
        return \ImapPolyfill\Support\ErrorStack::drainAlerts();
    }
}

if (!function_exists('imap_num_msg')) {
    function imap_num_msg(\IMAP\Connection $imap): int|false
    {
        $imap->ensureOpen();

        try {
            $status = $imap->selectOrExamine();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            // ext-imap's imap_num_msg is a cached client-side read (c-client's
            // stream->nmsgs), not a live query: it keeps returning the last
            // known count rather than false if the connection later breaks.
            return $imap->cachedNumMsg;
        }

        $imap->cachedNumMsg = $status['exists'] ?? 0;
        $imap->cachedNumRecent = $status['recent'] ?? 0;

        return $imap->cachedNumMsg;
    }
}

if (!function_exists('imap_check')) {
    function imap_check(\IMAP\Connection $imap): \stdClass|false
    {
        $imap->ensureOpen();

        try {
            $status = $imap->selectOrExamine();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        $result = new \stdClass();
        $result->Date = date('r');
        $result->Driver = 'imap';
        $result->Mailbox = $imap->mailbox;
        $result->Nmsgs = $status['exists'] ?? 0;
        $result->Recent = $status['recent'] ?? 0;

        return $result;
    }
}

if (!function_exists('imap_search')) {
    function imap_search(\IMAP\Connection $imap, string $criteria, int $flags = SE_FREE, string $charset = ''): array|false
    {
        $uidMode = ($flags & SE_UID)
            ? \Webklex\PHPIMAP\IMAP::ST_UID
            : \Webklex\PHPIMAP\IMAP::ST_MSGN;

        $imap->ensureOpen();

        $tokens = preg_split('/\s+/', trim($criteria));

        try {
            $imap->selectOrExamine();
            $ids = $imap->client->getConnection()->search($tokens, $uidMode)->validatedData();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        if ($ids === []) {
            return false;
        }

        return array_map('intval', $ids);
    }
}

if (!function_exists('imap_fetchheader')) {
    function imap_fetchheader(\IMAP\Connection $imap, int $message_num, int $flags = 0): string|false
    {
        $uidMode = ($flags & FT_UID)
            ? \Webklex\PHPIMAP\IMAP::ST_UID
            : \Webklex\PHPIMAP\IMAP::ST_MSGN;

        $imap->ensureOpen();

        try {
            $imap->selectOrExamine();
            $headers = $imap->client->getConnection()->headers([$message_num], 'RFC822', $uidMode)->validatedData();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        return $headers[$message_num] ?? reset($headers);
    }
}

if (!function_exists('imap_headerinfo')) {
    function imap_headerinfo(\IMAP\Connection $imap, int $message_num, int $from_length = 0, int $subject_length = 0): \stdClass|false
    {
        $imap->ensureOpen();

        try {
            $imap->selectOrExamine();
            $data = $imap->client->getConnection()
                ->fetch(['FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'], [$message_num], null, \Webklex\PHPIMAP\IMAP::ST_MSGN)
                ->validatedData();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        $message = $data[$message_num] ?? reset($data);

        return \ImapPolyfill\Message\HeaderInfo::build(
            $message['RFC822.HEADER'],
            $message['FLAGS'],
            $message['INTERNALDATE'],
            $message['RFC822.SIZE'],
            $message_num,
            $imap->client->host,
        );
    }
}

if (!function_exists('imap_fetch_overview')) {
    function imap_fetch_overview(\IMAP\Connection $imap, string $sequence, int $flags = 0): array|false
    {
        $uidMode = ($flags & FT_UID)
            ? \Webklex\PHPIMAP\IMAP::ST_UID
            : \Webklex\PHPIMAP\IMAP::ST_MSGN;

        $imap->ensureOpen();

        try {
            $status = $imap->selectOrExamine();
            $ids = \ImapPolyfill\Message\MessageSequence::expand($sequence, $status['exists'] ?? 0);

            if ($ids === []) {
                return [];
            }

            $connection = $imap->client->getConnection();
            $data = $connection
                ->fetch(['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'], $ids, null, $uidMode)
                ->validatedData();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

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
            $uid = $uidMode === \Webklex\PHPIMAP\IMAP::ST_UID ? $id : (int) $message['UID'];
            $msgno = $uidMode === \Webklex\PHPIMAP\IMAP::ST_UID
                ? $connection->getMessageNumber((string) $id)->validatedData()
                : $id;

            $result[] = \ImapPolyfill\Message\Overview::build(
                $message['RFC822.HEADER'],
                $message['FLAGS'],
                $message['INTERNALDATE'],
                (int) $message['RFC822.SIZE'],
                $uid,
                $msgno,
                $imap->client->host,
            );
        }

        return $result;
    }
}

if (!function_exists('imap_fetchstructure')) {
    function imap_fetchstructure(\IMAP\Connection $imap, int $message_num, int $flags = 0): \stdClass|false
    {
        $imap->ensureOpen();

        try {
            $imap->selectOrExamine();
            $parsed = \ImapPolyfill\Message\BodyStructureFetch::fetch($imap->client, $message_num, (bool) ($flags & FT_UID));
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        return \ImapPolyfill\Message\BodyStructure::build($parsed);
    }
}

if (!function_exists('imap_fetchbody')) {
    function imap_fetchbody(\IMAP\Connection $imap, int $message_num, string $section, int $flags = 0): string|false
    {
        $imap->ensureOpen();

        $uidMode = ($flags & FT_UID)
            ? \Webklex\PHPIMAP\IMAP::ST_UID
            : \Webklex\PHPIMAP\IMAP::ST_MSGN;
        // ext-imap's section "0" is a legacy alias for the top-level header,
        // not a literal MIME part index.
        $wireSection = $section === '0' ? 'HEADER' : $section;
        $item = ($flags & FT_PEEK) ? "BODY.PEEK[{$wireSection}]" : "BODY[{$wireSection}]";

        try {
            $imap->selectOrExamine();
            $data = $imap->client->getConnection()->fetch([$item], [$message_num], null, $uidMode)->validatedData();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        return $data[$message_num] ?? reset($data);
    }
}

if (!function_exists('imap_uid')) {
    function imap_uid(\IMAP\Connection $imap, int $message_num): int|false
    {
        $imap->ensureOpen();

        if ($message_num < 1) {
            throw new \ValueError('imap_uid(): Argument #2 ($message_num) must be greater than 0');
        }

        try {
            $status = $imap->selectOrExamine();

            if ($message_num > ($status['exists'] ?? 0)) {
                trigger_error('imap_uid(): Bad message number', E_USER_WARNING);

                return false;
            }

            $uids = $imap->client->getConnection()->getUid()->validatedData();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        return (int) $uids[$message_num];
    }
}

if (!function_exists('imap_msgno')) {
    function imap_msgno(\IMAP\Connection $imap, int $message_uid): int
    {
        $imap->ensureOpen();

        if ($message_uid < 1) {
            throw new \ValueError('imap_msgno(): Argument #2 ($message_uid) must be greater than 0');
        }

        try {
            $imap->selectOrExamine();

            return (int) $imap->client->getConnection()->getMessageNumber((string) $message_uid)->validatedData();
        } catch (\Webklex\PHPIMAP\Exceptions\MessageNotFoundException) {
            return 0;
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return 0;
        }
    }
}

if (!function_exists('imap_list')) {
    function imap_list(\IMAP\Connection $imap, string $reference, string $pattern): array|false
    {
        $imap->ensureOpen();

        $ref = \ImapPolyfill\Mailbox\MailboxReference::parse($reference);

        try {
            $folders = $imap->client->getConnection()->folders($ref->bareReference, $pattern)->validatedData();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        if ($folders === []) {
            return false;
        }

        return array_map(static fn (string $name): string => $ref->displayPrefix.$name, array_keys($folders));
    }
}

if (!function_exists('imap_getmailboxes')) {
    function imap_getmailboxes(\IMAP\Connection $imap, string $reference, string $pattern): array|false
    {
        $imap->ensureOpen();

        $ref = \ImapPolyfill\Mailbox\MailboxReference::parse($reference);

        $flagBits = [
            '\noinferiors' => LATT_NOINFERIORS,
            '\noselect' => LATT_NOSELECT,
            '\marked' => LATT_MARKED,
            '\unmarked' => LATT_UNMARKED,
            '\haschildren' => LATT_HASCHILDREN,
            '\hasnochildren' => LATT_HASNOCHILDREN,
        ];

        try {
            $folders = $imap->client->getConnection()->folders($ref->bareReference, $pattern)->validatedData();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        if ($folders === []) {
            return false;
        }

        $result = [];
        foreach ($folders as $name => $info) {
            $attributes = 0;
            foreach ($info['flags'] as $flag) {
                $attributes |= $flagBits[strtolower($flag)] ?? 0;
            }

            $mailbox = new \stdClass();
            $mailbox->name = $ref->displayPrefix.$name;
            $mailbox->attributes = $attributes;
            $mailbox->delimiter = $info['delimiter'];
            $result[] = $mailbox;
        }

        return $result;
    }
}

if (!function_exists('imap_setflag_full')) {
    function imap_setflag_full(\IMAP\Connection $imap, string $sequence, string $flag, int $options = 0): bool
    {
        $imap->ensureOpen();

        $command = ($options & ST_UID) ? 'UID STORE' : 'STORE';
        $flagsAtom = '('.trim($flag).')';

        try {
            $imap->selectOrExamine();
            $imap->client->getConnection()->requestAndResponse($command, [$sequence, '+FLAGS.SILENT', $flagsAtom]);
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());
        }

        return true;
    }
}

if (!function_exists('imap_clearflag_full')) {
    function imap_clearflag_full(\IMAP\Connection $imap, string $sequence, string $flag, int $options = 0): bool
    {
        $imap->ensureOpen();

        $command = ($options & ST_UID) ? 'UID STORE' : 'STORE';
        $flagsAtom = '('.trim($flag).')';

        try {
            $imap->selectOrExamine();
            $imap->client->getConnection()->requestAndResponse($command, [$sequence, '-FLAGS.SILENT', $flagsAtom]);
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());
        }

        return true;
    }
}

if (!function_exists('imap_delete')) {
    function imap_delete(\IMAP\Connection $imap, string $message_nums, int $flags = 0): bool
    {
        return imap_setflag_full($imap, $message_nums, '\\Deleted', $flags);
    }
}

if (!function_exists('imap_undelete')) {
    function imap_undelete(\IMAP\Connection $imap, string $message_nums, int $flags = 0): bool
    {
        return imap_clearflag_full($imap, $message_nums, '\\Deleted', $flags);
    }
}

if (!function_exists('imap_expunge')) {
    function imap_expunge(\IMAP\Connection $imap): bool
    {
        $imap->ensureOpen();

        try {
            $imap->selectOrExamine();
            $imap->client->expunge();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());
        }

        return true;
    }
}

if (!function_exists('imap_append')) {
    function imap_append(\IMAP\Connection $imap, string $folder, string $message, ?string $options = null, ?string $internal_date = null): bool
    {
        $imap->ensureOpen();

        $folderName = \ImapPolyfill\Mailbox\MailboxReference::parse($folder)->bareReference;
        $flags = $options !== null ? preg_split('/\s+/', trim($options)) : null;

        try {
            $imap->client->getFolder($folderName)->appendMessage($message, $flags, $internal_date);
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }
}

if (!function_exists('imap_base64')) {
    function imap_base64(string $text): string|false
    {
        return base64_decode($text);
    }
}

if (!function_exists('imap_qprint')) {
    function imap_qprint(string $text): string|false
    {
        return quoted_printable_decode($text);
    }
}

if (!function_exists('imap_8bit')) {
    function imap_8bit(string $text): string|false
    {
        return quoted_printable_encode($text);
    }
}

if (!function_exists('imap_binary')) {
    function imap_binary(string $text): string|false
    {
        // Matches c-client's rfc822_binary: base64 wrapped at 60 chars/line.
        return chunk_split(base64_encode($text), 60, "\n");
    }
}

if (!function_exists('imap_utf8_to_mutf7')) {
    function imap_utf8_to_mutf7(string $string): string|false
    {
        $result = @mb_convert_encoding($string, 'UTF7-IMAP', 'UTF-8');

        return $result !== false ? $result : false;
    }
}

if (!function_exists('imap_mutf7_to_utf8')) {
    function imap_mutf7_to_utf8(string $string): string|false
    {
        $result = @mb_convert_encoding($string, 'UTF-8', 'UTF7-IMAP');

        return $result !== false ? $result : false;
    }
}

if (!function_exists('imap_utf7_encode')) {
    function imap_utf7_encode(string $string): string
    {
        return mb_convert_encoding($string, 'UTF7-IMAP', 'ISO-8859-1');
    }
}

if (!function_exists('imap_utf7_decode')) {
    function imap_utf7_decode(string $string): string|false
    {
        $result = @mb_convert_encoding($string, 'ISO-8859-1', 'UTF7-IMAP');

        return $result !== false ? $result : false;
    }
}

if (!function_exists('imap_rfc822_write_address')) {
    function imap_rfc822_write_address(string $mailbox, string $hostname, string $personal): string|false
    {
        $address = "{$mailbox}@{$hostname}";

        if ($personal === '') {
            return $address;
        }

        if (str_contains($personal, ',')) {
            $personal = '"'.$personal.'"';
        }

        return "{$personal} <{$address}>";
    }
}

if (!function_exists('imap_mime_header_decode')) {
    function imap_mime_header_decode(string $string): array|false
    {
        return \ImapPolyfill\Mime\MimeText::decodeSegments($string);
    }
}

if (!function_exists('imap_rfc822_parse_headers')) {
    function imap_rfc822_parse_headers(string $headers, string $default_hostname = 'UNKNOWN'): \stdClass
    {
        return \ImapPolyfill\Message\HeaderInfo::buildFromHeaderOnly($headers, $default_hostname);
    }
}

if (!function_exists('imap_createmailbox')) {
    function imap_createmailbox(\IMAP\Connection $imap, string $mailbox): bool
    {
        $imap->ensureOpen();

        $folderName = \ImapPolyfill\Mailbox\MailboxReference::parse($mailbox)->bareReference;

        try {
            $imap->client->createFolder($folderName);
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }
}

if (!function_exists('imap_create')) {
    function imap_create(\IMAP\Connection $imap, string $mailbox): bool
    {
        return imap_createmailbox($imap, $mailbox);
    }
}

if (!function_exists('imap_deletemailbox')) {
    function imap_deletemailbox(\IMAP\Connection $imap, string $mailbox): bool
    {
        $imap->ensureOpen();

        $folderName = \ImapPolyfill\Mailbox\MailboxReference::parse($mailbox)->bareReference;

        try {
            $imap->client->deleteFolder($folderName);
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }
}

if (!function_exists('imap_renamemailbox')) {
    function imap_renamemailbox(\IMAP\Connection $imap, string $from, string $to): bool
    {
        $imap->ensureOpen();

        $fromName = \ImapPolyfill\Mailbox\MailboxReference::parse($from)->bareReference;
        $toName = \ImapPolyfill\Mailbox\MailboxReference::parse($to)->bareReference;

        try {
            $imap->client->getFolder($fromName)->rename($toName);
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }
}

if (!function_exists('imap_rename')) {
    function imap_rename(\IMAP\Connection $imap, string $from, string $to): bool
    {
        return imap_renamemailbox($imap, $from, $to);
    }
}

if (!function_exists('imap_subscribe')) {
    function imap_subscribe(\IMAP\Connection $imap, string $mailbox): bool
    {
        $imap->ensureOpen();

        $folderName = \ImapPolyfill\Mailbox\MailboxReference::parse($mailbox)->bareReference;

        try {
            $imap->client->getFolder($folderName)->subscribe();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }
}

if (!function_exists('imap_unsubscribe')) {
    function imap_unsubscribe(\IMAP\Connection $imap, string $mailbox): bool
    {
        $imap->ensureOpen();

        $folderName = \ImapPolyfill\Mailbox\MailboxReference::parse($mailbox)->bareReference;

        try {
            $imap->client->getFolder($folderName)->unsubscribe();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }
}

if (!function_exists('imap_num_recent')) {
    function imap_num_recent(\IMAP\Connection $imap): int|false
    {
        $imap->ensureOpen();

        try {
            $status = $imap->selectOrExamine();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            // Cached client-side read, like imap_num_msg; see its comment.
            return $imap->cachedNumRecent;
        }

        $imap->cachedNumMsg = $status['exists'] ?? 0;
        $imap->cachedNumRecent = $status['recent'] ?? 0;

        return $imap->cachedNumRecent;
    }
}

if (!function_exists('imap_is_open')) {
    function imap_is_open(\IMAP\Connection $imap): bool
    {
        // Deliberately does not call ensureOpen(): unlike every other
        // wrapper, this function's entire purpose is to check openness
        // without throwing, matching ext-imap's own "doesn't throw" note.
        return !$imap->closed;
    }
}

if (!function_exists('imap_reopen')) {
    function imap_reopen(\IMAP\Connection $imap, string $mailbox, int $flags = 0, int $retries = 0): bool
    {
        $imap->ensureOpen();

        // Scoped to switching folders on the same already-connected client:
        // this polyfill doesn't retain the original credentials needed to
        // reconnect to a genuinely different host.
        $spec = \ImapPolyfill\Mailbox\MailboxSpec::parse($mailbox);
        $readOnly = (bool) ($flags & OP_READONLY);

        try {
            $folder = $imap->client->getFolder($spec->folder);
            $status = $readOnly ? $folder->examine() : $folder->select();
        } catch (\Throwable $e) {
            \ImapPolyfill\Support\ErrorStack::push($e->getMessage());

            return false;
        }

        $imap->folder = $spec->folder;
        $imap->readOnly = $readOnly;
        $imap->cachedNumMsg = $status['exists'] ?? 0;
        $imap->cachedNumRecent = $status['recent'] ?? 0;

        return true;
    }
}
