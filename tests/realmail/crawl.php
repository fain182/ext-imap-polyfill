<?php

/**
 * Asks a real account about every item snapshot.php listed, and prints one
 * line per answer in the format tests/fuzz/compare.php reads:
 *
 *     php tests/realmail/crawl.php .env.realmail corpus > answers
 *
 * Like the fuzzer's evaluate.php it does not know which engine it runs
 * under: the same file runs against the polyfill and, in the parity image,
 * against the genuine extension.
 *
 * Every function called here only reads, and the connection is opened
 * OP_READONLY on top of that. Bodies are fetched with FT_PEEK even so, so
 * that a server ignoring EXAMINE still sets no \Seen.
 */

$accountPath = $argv[1] ?? null;
$corpusPath = $argv[2] ?? null;

if ($accountPath === null || $corpusPath === null || !is_file($corpusPath)) {
    fwrite(STDERR, "usage: crawl.php <account file> <corpus>\n");

    exit(2);
}

if (!extension_loaded('imap')) {
    require __DIR__.'/../../vendor/autoload.php';
}

require __DIR__.'/account.php';

$account = realmailAccount($accountPath);

/**
 * tests/fuzz/evaluate.php's encoding, with one change: an answer longer
 * than an attachment worth reading is replaced by its length and hash, or a
 * mailbox of PDFs makes answer files nobody can diff.
 */
function encode(mixed $value): string
{
    if (is_string($value)) {
        return strlen($value) > 65536
            ? 'big:'.strlen($value).':'.sha1($value)
            : 'hex:'.bin2hex($value);
    }

    if (is_object($value)) {
        return '{'.encode(get_object_vars($value)).'}';
    }

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $key => $item) {
            $parts[] = (is_int($key) ? (string) $key : 'hex:'.bin2hex($key)).'=>'.encode($item);
        }

        return '['.implode(',', $parts).']';
    }

    return get_debug_type($value).':'.var_export($value, true);
}

function answer(int $index, string $label, callable $call): void
{
    imap_errors();
    $warnings = [];

    // Severity is not compared, as in the fuzzer: E_USER_WARNING against
    // E_WARNING is a documented userland limit.
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $answer = encode($call());
    } catch (\Throwable $thrown) {
        $answer = 'threw:'.$thrown::class.':'.encode($thrown->getMessage());
    } finally {
        restore_error_handler();
    }

    printf(
        "%d\t%s\t%s\terrors=%s\twarnings=%s\n",
        $index,
        $label,
        $answer,
        encode(imap_errors() ?: []),
        encode($warnings),
    );
}

/**
 * Uids the snapshot did not see are dropped from a folder-wide answer: mail
 * that arrived between the two runs is not a divergence.
 *
 * @param list<int>|false $uids
 * @return list<int>|false
 */
function withinSnapshot(array|false $uids, int $min, int $max): array|false
{
    return $uids === false
        ? false
        : array_values(array_filter($uids, static fn (int $uid): bool => $uid >= $min && $uid <= $max));
}

$searches = [
    'ALL', 'SEEN', 'UNSEEN', 'FLAGGED', 'ANSWERED', 'DELETED',
    'SINCE "1-Jan-2026"', 'BEFORE "1-Jan-2020"', 'ON "1-Jun-2026"',
    'SUBJECT "re"', 'FROM "noreply"', 'TO "@"', 'TEXT "unsubscribe"', 'BODY "grazie"',
    'LARGER 100000', 'SMALLER 2000', 'UNSEEN SINCE "1-Oct-2026"', 'OR FLAGGED ANSWERED',
];

$sorts = [
    'date' => SORTDATE, 'arrival' => SORTARRIVAL, 'from' => SORTFROM, 'subject' => SORTSUBJECT,
    'to' => SORTTO, 'cc' => SORTCC, 'size' => SORTSIZE,
];

$connection = realmailOpen($account, $account['folders'][0]);
$selected = $account['folders'][0];
$handle = fopen($corpusPath, 'r');
$index = -1;

while (($line = fgets($handle)) !== false) {
    $line = trim($line);

    if ($line === '') {
        continue;
    }

    $index++;
    $item = json_decode(hex2bin($line), true, flags: JSON_THROW_ON_ERROR);

    if (isset($item['folder']) && $item['folder'] !== $selected) {
        imap_reopen($connection, $account['server'].$item['folder'], OP_READONLY);
        imap_errors();
        $selected = $item['folder'];
    }

    switch ($item['kind']) {
        case 'server':
            $server = $account['server'];
            answer($index, 'list', static fn () => imap_list($connection, $server, '*'));
            answer($index, 'getmailboxes', static fn () => imap_getmailboxes($connection, $server, '*'));
            answer($index, 'lsub', static fn () => imap_lsub($connection, $server, '*'));
            break;

        case 'folder':
            [$min, $max] = [$item['min'], $item['max']];
            $mailbox = $account['server'].$item['folder'];
            answer($index, 'status', static function () use ($connection, $mailbox): mixed {
                $status = imap_status($connection, $mailbox, SA_UIDVALIDITY);

                return $status === false ? false : ['flags' => $status->flags, 'uidvalidity' => $status->uidvalidity];
            });

            foreach ($searches as $criteria) {
                answer($index, 'search '.$criteria, static fn () => withinSnapshot(imap_search($connection, $criteria, SE_UID), $min, $max));
            }

            answer($index, 'search charset', static fn () => withinSnapshot(imap_search($connection, 'SUBJECT "è"', SE_UID, 'UTF-8'), $min, $max));

            foreach ($sorts as $name => $criterion) {
                foreach ([0, 1] as $reverse) {
                    answer($index, "sort {$name} {$reverse}", static fn () => withinSnapshot(imap_sort($connection, $criterion, $reverse, SE_UID), $min, $max));
                }
            }

            // Not filtered to the snapshot: a thread tree cannot be pruned
            // without changing its shape. Mail arriving between the two runs
            // shows up here and only here.
            answer($index, 'thread', static fn () => imap_thread($connection, SE_UID));
            break;

        case 'message':
            $uid = $item['uid'];
            answer($index, 'msgno', static fn () => imap_msgno($connection, $uid));
            answer($index, 'fetch_overview', static fn () => imap_fetch_overview($connection, (string) $uid, FT_UID));
            answer($index, 'headerinfo', static fn () => imap_headerinfo($connection, imap_msgno($connection, $uid)));
            answer($index, 'fetchstructure', static fn () => imap_fetchstructure($connection, $uid, FT_UID));
            answer($index, 'fetchheader', static fn () => imap_fetchheader($connection, $uid, FT_UID));
            answer($index, 'parse_headers', static fn () => imap_rfc822_parse_headers((string) imap_fetchheader($connection, $uid, FT_UID)));

            foreach ($item['sections'] as $section) {
                answer($index, 'fetchbody', static fn () => imap_fetchbody($connection, $uid, $section, FT_UID | FT_PEEK));
                answer($index, 'fetchmime', static fn () => imap_fetchmime($connection, $uid, $section, FT_UID | FT_PEEK));
                answer($index, 'bodystruct', static fn () => imap_bodystruct($connection, imap_msgno($connection, $uid), $section));
            }
            break;

        case 'header':
            $value = hex2bin($item['value']);
            answer($index, 'mime_header_decode', static fn () => imap_mime_header_decode($value));
            answer($index, 'utf8', static fn () => imap_utf8($value));

            if ($item['name'] !== 'Subject') {
                answer($index, 'parse_adrlist', static fn () => imap_rfc822_parse_adrlist($value, 'default.host'));
            }
            break;
    }
}

fclose($handle);
imap_close($connection);
imap_errors();
imap_alerts();
