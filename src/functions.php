<?php

if (!function_exists('imap_utf8')) {
    function imap_utf8(string $mime_encoded_text): string
    {
        return \Fain182\ImapPolyfill\MimeText::decode($mime_encoded_text);
    }
}

if (!function_exists('imap_rfc822_parse_adrlist')) {
    function imap_rfc822_parse_adrlist(string $string, string $default_hostname): array
    {
        return \Fain182\ImapPolyfill\AddressList::parse($string, $default_hostname);
    }
}

if (!function_exists('imap_open')) {
    function imap_open(string $mailbox, string $user, string $password, int $flags = 0, int $retries = 0, array $options = []): \IMAP\Connection|false
    {
        $spec = \Fain182\ImapPolyfill\MailboxSpec::parse($mailbox);

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
        $connected = false;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client->connect();
                $connected = true;
                break;
            } catch (\Throwable $e) {
                \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());
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
            }
        } catch (\Throwable $e) {
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());
            trigger_error("imap_open(): Couldn't open stream {$mailbox}", E_USER_WARNING);

            return false;
        }

        $connection = new \IMAP\Connection($client, $spec->folder, $mailbox, $readOnly);
        $connection->cachedNumMsg = $numMsg;

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
            return \Fain182\ImapPolyfill\Timeouts::get($timeout_type);
        }

        return \Fain182\ImapPolyfill\Timeouts::set($timeout_type, $timeout);
    }
}

if (!function_exists('imap_last_error')) {
    function imap_last_error(): string|false
    {
        return \Fain182\ImapPolyfill\ErrorStack::last();
    }
}

if (!function_exists('imap_errors')) {
    function imap_errors(): array|false
    {
        return \Fain182\ImapPolyfill\ErrorStack::drainErrors();
    }
}

if (!function_exists('imap_alerts')) {
    function imap_alerts(): array|false
    {
        return \Fain182\ImapPolyfill\ErrorStack::drainAlerts();
    }
}

if (!function_exists('imap_num_msg')) {
    function imap_num_msg(\IMAP\Connection $imap): int|false
    {
        $imap->ensureOpen();

        try {
            $status = $imap->selectOrExamine();
        } catch (\Throwable $e) {
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

            // ext-imap's imap_num_msg is a cached client-side read (c-client's
            // stream->nmsgs), not a live query: it keeps returning the last
            // known count rather than false if the connection later breaks.
            return $imap->cachedNumMsg;
        }

        $imap->cachedNumMsg = $status['exists'] ?? 0;

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
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

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
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

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
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

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
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

            return false;
        }

        $message = $data[$message_num] ?? reset($data);

        return \Fain182\ImapPolyfill\HeaderInfo::build(
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
            $ids = \Fain182\ImapPolyfill\MessageSequence::expand($sequence, $status['exists'] ?? 0);

            if ($ids === []) {
                return [];
            }

            $connection = $imap->client->getConnection();
            $data = $connection
                ->fetch(['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'RFC822.HEADER'], $ids, null, $uidMode)
                ->validatedData();
        } catch (\Throwable $e) {
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

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

            $result[] = \Fain182\ImapPolyfill\Overview::build(
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
            $parsed = \Fain182\ImapPolyfill\BodyStructureFetch::fetch($imap->client, $message_num, (bool) ($flags & FT_UID));
        } catch (\Throwable $e) {
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

            return false;
        }

        return \Fain182\ImapPolyfill\BodyStructure::build($parsed);
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
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

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
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

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
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

            return 0;
        }
    }
}

if (!function_exists('imap_list')) {
    function imap_list(\IMAP\Connection $imap, string $reference, string $pattern): array|false
    {
        $imap->ensureOpen();

        $ref = \Fain182\ImapPolyfill\MailboxReference::parse($reference);

        try {
            $folders = $imap->client->getConnection()->folders($ref->bareReference, $pattern)->validatedData();
        } catch (\Throwable $e) {
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

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

        $ref = \Fain182\ImapPolyfill\MailboxReference::parse($reference);

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
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

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
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());
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

if (!function_exists('imap_expunge')) {
    function imap_expunge(\IMAP\Connection $imap): bool
    {
        $imap->ensureOpen();

        try {
            $imap->selectOrExamine();
            $imap->client->expunge();
        } catch (\Throwable $e) {
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());
        }

        return true;
    }
}

if (!function_exists('imap_append')) {
    function imap_append(\IMAP\Connection $imap, string $folder, string $message, ?string $options = null, ?string $internal_date = null): bool
    {
        $imap->ensureOpen();

        $folderName = \Fain182\ImapPolyfill\MailboxReference::parse($folder)->bareReference;
        $flags = $options !== null ? preg_split('/\s+/', trim($options)) : null;

        try {
            $imap->client->getFolder($folderName)->appendMessage($message, $flags, $internal_date);
        } catch (\Throwable $e) {
            \Fain182\ImapPolyfill\ErrorStack::push($e->getMessage());

            return false;
        }

        return true;
    }
}
