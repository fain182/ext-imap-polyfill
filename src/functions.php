<?php

if (!function_exists('imap_utf8')) {
    function imap_utf8(string $mime_encoded_text): string
    {
        return \ImapPolyfill\Mime\MimeText::decode($mime_encoded_text);
    }
}

if (!function_exists('imap_rfc822_parse_adrlist')) {
    /**
     * @return \stdClass[]
     */
    function imap_rfc822_parse_adrlist(string $string, string $default_hostname): array
    {
        return \ImapPolyfill\Address\AddressList::parse($string, $default_hostname)->toLegacyArray();
    }
}

if (!function_exists('imap_open')) {
    /**
     * @param array<string, mixed> $options ignored — see README limitations
     */
    function imap_open(string $mailbox, string $user, string $password, int $flags = 0, int $retries = 0, array $options = []): \IMAP\Connection|false
    {
        $connection = \ImapPolyfill\Session\Session::open($mailbox, $user, $password, $flags, $retries);

        if ($connection === false) {
            trigger_error("imap_open(): Couldn't open stream {$mailbox}", E_USER_WARNING);
        }

        return $connection;
    }
}

if (!function_exists('imap_close')) {
    function imap_close(\IMAP\Connection $imap, int $flags = 0): bool
    {
        return (new \ImapPolyfill\Session\Session($imap))->close($flags);
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
    /**
     * @return string[]|false
     */
    function imap_errors(): array|false
    {
        return \ImapPolyfill\Support\ErrorStack::drainErrors();
    }
}

if (!function_exists('imap_alerts')) {
    /**
     * @return string[]|false
     */
    function imap_alerts(): array|false
    {
        return \ImapPolyfill\Support\ErrorStack::drainAlerts();
    }
}

if (!function_exists('imap_num_msg')) {
    function imap_num_msg(\IMAP\Connection $imap): int|false
    {
        return (new \ImapPolyfill\Session\Session($imap))->numMessages();
    }
}

if (!function_exists('imap_check')) {
    function imap_check(\IMAP\Connection $imap): \stdClass|false
    {
        return (new \ImapPolyfill\Session\Session($imap))->check();
    }
}

if (!function_exists('imap_mailboxmsginfo')) {
    function imap_mailboxmsginfo(\IMAP\Connection $imap): \stdClass|false
    {
        return (new \ImapPolyfill\Session\Session($imap))->mailboxMsgInfo();
    }
}

if (!function_exists('imap_search')) {
    /**
     * @return int[]|false
     */
    function imap_search(\IMAP\Connection $imap, string $criteria, int $flags = SE_FREE, string $charset = ''): array|false
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->search($criteria, $flags, $charset);
    }
}

if (!function_exists('imap_thread')) {
    /**
     * @return array<string, int>|false
     */
    function imap_thread(\IMAP\Connection $imap, int $flags = SE_FREE): array|false
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->thread($flags);
    }
}

if (!function_exists('imap_headers')) {
    /**
     * @return string[]
     */
    function imap_headers(\IMAP\Connection $imap): array
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->headers();
    }
}

if (!function_exists('imap_sort')) {
    /**
     * @return int[]|false
     */
    function imap_sort(\IMAP\Connection $imap, int $criteria, bool $reverse, int $flags = 0, ?string $search_criteria = null, ?string $charset = null): array|false
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->sort($criteria, $reverse, $flags, $search_criteria, $charset);
    }
}

if (!function_exists('imap_fetchheader')) {
    function imap_fetchheader(\IMAP\Connection $imap, int $message_num, int $flags = 0): string|false
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->fetchHeader($message_num, $flags);
    }
}

if (!function_exists('imap_headerinfo')) {
    function imap_headerinfo(\IMAP\Connection $imap, int $message_num, int $from_length = 0, int $subject_length = 0): \stdClass|false
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->headerInfo($message_num);
    }
}

if (!function_exists('imap_fetch_overview')) {
    /**
     * @return \stdClass[]|false
     */
    function imap_fetch_overview(\IMAP\Connection $imap, string $sequence, int $flags = 0): array|false
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->fetchOverview($sequence, $flags);
    }
}

if (!function_exists('imap_fetchstructure')) {
    function imap_fetchstructure(\IMAP\Connection $imap, int $message_num, int $flags = 0): \stdClass|false
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->fetchStructure($message_num, $flags);
    }
}

if (!function_exists('imap_fetchbody')) {
    function imap_fetchbody(\IMAP\Connection $imap, int $message_num, string $section, int $flags = 0): string|false
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->fetchBody($message_num, $section, $flags);
    }
}

if (!function_exists('imap_body')) {
    function imap_body(\IMAP\Connection $imap, int $message_num, int $flags = 0): string|false
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->body($message_num, $flags);
    }
}

if (!function_exists('imap_fetchmime')) {
    function imap_fetchmime(\IMAP\Connection $imap, int $message_num, string $section, int $flags = 0): string|false
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->fetchMime($message_num, $section, $flags);
    }
}

if (!function_exists('imap_bodystruct')) {
    function imap_bodystruct(\IMAP\Connection $imap, int $message_num, string $section): \stdClass|false
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->bodyStruct($message_num, $section);
    }
}

if (!function_exists('imap_savebody')) {
    function imap_savebody(\IMAP\Connection $imap, mixed $file, int $message_num, string $section = '', int $flags = 0): bool
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->saveBody($file, $message_num, $section, $flags);
    }
}

if (!function_exists('imap_gc')) {
    function imap_gc(\IMAP\Connection $imap, int $flags): bool
    {
        return (new \ImapPolyfill\Session\Session($imap))->gc($flags);
    }
}

if (!function_exists('imap_fetchtext')) {
    function imap_fetchtext(\IMAP\Connection $imap, int $message_num, int $flags = 0): string|false
    {
        return imap_body($imap, $message_num, $flags);
    }
}

if (!function_exists('imap_mail_copy')) {
    function imap_mail_copy(\IMAP\Connection $imap, string $message_nums, string $mailbox, int $flags = 0): bool
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->copy($message_nums, $mailbox, $flags);
    }
}

if (!function_exists('imap_mail_move')) {
    function imap_mail_move(\IMAP\Connection $imap, string $message_nums, string $mailbox, int $flags = 0): bool
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->move($message_nums, $mailbox, $flags);
    }
}

if (!function_exists('imap_ping')) {
    function imap_ping(\IMAP\Connection $imap): bool
    {
        return (new \ImapPolyfill\Session\Session($imap))->ping();
    }
}

if (!function_exists('imap_status')) {
    function imap_status(\IMAP\Connection $imap, string $mailbox, int $flags): \stdClass|false
    {
        return (new \ImapPolyfill\Session\MailboxHierarchy($imap))->status($mailbox, $flags);
    }
}

if (!function_exists('imap_uid')) {
    function imap_uid(\IMAP\Connection $imap, int $message_num): int|false
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->uid($message_num);
    }
}

if (!function_exists('imap_msgno')) {
    function imap_msgno(\IMAP\Connection $imap, int $message_uid): int
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->msgno($message_uid);
    }
}

if (!function_exists('imap_list')) {
    /**
     * @return string[]|false
     */
    function imap_list(\IMAP\Connection $imap, string $reference, string $pattern): array|false
    {
        return (new \ImapPolyfill\Session\MailboxHierarchy($imap))->listMailboxes($reference, $pattern);
    }
}

if (!function_exists('imap_lsub')) {
    /**
     * @return string[]|false
     */
    function imap_lsub(\IMAP\Connection $imap, string $reference, string $pattern): array|false
    {
        return (new \ImapPolyfill\Session\MailboxHierarchy($imap))->listSubscribed($reference, $pattern);
    }
}

if (!function_exists('imap_listsubscribed')) {
    /**
     * @return string[]|false
     */
    function imap_listsubscribed(\IMAP\Connection $imap, string $reference, string $pattern): array|false
    {
        return imap_lsub($imap, $reference, $pattern);
    }
}

if (!function_exists('imap_getsubscribed')) {
    /**
     * @return \stdClass[]|false
     */
    function imap_getsubscribed(\IMAP\Connection $imap, string $reference, string $pattern): array|false
    {
        return (new \ImapPolyfill\Session\MailboxHierarchy($imap))->getSubscribed($reference, $pattern);
    }
}

if (!function_exists('imap_listmailbox')) {
    /**
     * @return string[]|false
     */
    function imap_listmailbox(\IMAP\Connection $imap, string $reference, string $pattern): array|false
    {
        return imap_list($imap, $reference, $pattern);
    }
}

if (!function_exists('imap_getmailboxes')) {
    /**
     * @return \stdClass[]|false
     */
    function imap_getmailboxes(\IMAP\Connection $imap, string $reference, string $pattern): array|false
    {
        return (new \ImapPolyfill\Session\MailboxHierarchy($imap))->getMailboxes($reference, $pattern);
    }
}

if (!function_exists('imap_setflag_full')) {
    function imap_setflag_full(\IMAP\Connection $imap, string $sequence, string $flag, int $options = 0): bool
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->setFlagFull($sequence, $flag, $options);
    }
}

if (!function_exists('imap_clearflag_full')) {
    function imap_clearflag_full(\IMAP\Connection $imap, string $sequence, string $flag, int $options = 0): bool
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->clearFlagFull($sequence, $flag, $options);
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
        return (new \ImapPolyfill\Session\Mailbox($imap))->expunge();
    }
}

if (!function_exists('imap_append')) {
    function imap_append(\IMAP\Connection $imap, string $folder, string $message, ?string $options = null, ?string $internal_date = null): bool
    {
        return (new \ImapPolyfill\Session\Mailbox($imap))->append($folder, $message, $options, $internal_date);
    }
}

if (!function_exists('imap_base64')) {
    function imap_base64(string $string): string|false
    {
        return base64_decode($string);
    }
}

if (!function_exists('imap_qprint')) {
    function imap_qprint(string $string): string|false
    {
        return quoted_printable_decode($string);
    }
}

if (!function_exists('imap_8bit')) {
    function imap_8bit(string $string): string|false
    {
        return quoted_printable_encode($string);
    }
}

if (!function_exists('imap_binary')) {
    function imap_binary(string $string): string|false
    {
        return \ImapPolyfill\Mime\Base64Text::encode($string);
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
    /**
     * @return \stdClass[]|false
     */
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
        return (new \ImapPolyfill\Session\MailboxHierarchy($imap))->createMailbox($mailbox);
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
        return (new \ImapPolyfill\Session\MailboxHierarchy($imap))->deleteMailbox($mailbox);
    }
}

if (!function_exists('imap_renamemailbox')) {
    function imap_renamemailbox(\IMAP\Connection $imap, string $from, string $to): bool
    {
        return (new \ImapPolyfill\Session\MailboxHierarchy($imap))->renameMailbox($from, $to);
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
        return (new \ImapPolyfill\Session\MailboxHierarchy($imap))->subscribe($mailbox);
    }
}

if (!function_exists('imap_unsubscribe')) {
    function imap_unsubscribe(\IMAP\Connection $imap, string $mailbox): bool
    {
        return (new \ImapPolyfill\Session\MailboxHierarchy($imap))->unsubscribe($mailbox);
    }
}

if (!function_exists('imap_mail_compose')) {
    /**
     * @param array<array-key, mixed> $envelope
     * @param array<array-key, mixed> $bodies
     */
    function imap_mail_compose(array $envelope, array $bodies): string|false
    {
        return \ImapPolyfill\Mime\ComposedMessage::compose($envelope, $bodies);
    }
}

if (!function_exists('imap_get_quota')) {
    /**
     * @return array<string, int|array<string, int>>|false
     */
    function imap_get_quota(\IMAP\Connection $imap, string $quota_root): array|false
    {
        $quota = (new \ImapPolyfill\Session\MailboxHierarchy($imap))->getQuota($quota_root);

        if ($quota === false) {
            trigger_error('imap_get_quota(): C-client imap_getquota failed', E_USER_WARNING);
        }

        return $quota;
    }
}

if (!function_exists('imap_get_quotaroot')) {
    /**
     * @return array<string, int|array<string, int>>|false
     */
    function imap_get_quotaroot(\IMAP\Connection $imap, string $mailbox): array|false
    {
        $quota = (new \ImapPolyfill\Session\MailboxHierarchy($imap))->getQuotaRoot($mailbox);

        if ($quota === false) {
            trigger_error('imap_get_quotaroot(): C-client imap_getquotaroot failed', E_USER_WARNING);
        }

        return $quota;
    }
}

if (!function_exists('imap_set_quota')) {
    function imap_set_quota(\IMAP\Connection $imap, string $quota_root, int $mailbox_size): bool
    {
        return (new \ImapPolyfill\Session\MailboxHierarchy($imap))->setQuota($quota_root, $mailbox_size);
    }
}

if (!function_exists('imap_num_recent')) {
    function imap_num_recent(\IMAP\Connection $imap): int|false
    {
        return (new \ImapPolyfill\Session\Session($imap))->numRecent();
    }
}

if (!function_exists('imap_is_open')) {
    function imap_is_open(\IMAP\Connection $imap): bool
    {
        // Deliberately does not call ensureOpen(): unlike every other
        // wrapper, this function's entire purpose is to check openness
        // without throwing, matching ext-imap's own "doesn't throw" note.
        return $imap->isOpen();
    }
}

if (!function_exists('imap_reopen')) {
    function imap_reopen(\IMAP\Connection $imap, string $mailbox, int $flags = 0, int $retries = 0): bool
    {
        return (new \ImapPolyfill\Session\Session($imap))->reopen($mailbox, $flags, $retries);
    }
}
