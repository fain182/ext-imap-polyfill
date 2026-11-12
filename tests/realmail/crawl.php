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
require __DIR__.'/../fuzz/answer.php';

$account = realmailAccount($accountPath);

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
            $msgno = 0;
            $header = '';
            answer($index, 'msgno', static function () use ($connection, $uid, &$msgno): int {
                return $msgno = imap_msgno($connection, $uid);
            });
            answer($index, 'fetch_overview', static fn () => imap_fetch_overview($connection, (string) $uid, FT_UID));
            answer($index, 'headerinfo', static fn () => imap_headerinfo($connection, $msgno));
            answer($index, 'fetchstructure', static fn () => imap_fetchstructure($connection, $uid, FT_UID));
            answer($index, 'fetchheader', static function () use ($connection, $uid, &$header): string|false {
                $fetched = imap_fetchheader($connection, $uid, FT_UID);
                $header = (string) $fetched;

                return $fetched;
            });
            answer($index, 'parse_headers', static fn () => imap_rfc822_parse_headers($header));

            foreach ($item['sections'] as $section) {
                answer($index, 'fetchbody', static fn () => imap_fetchbody($connection, $uid, $section, FT_UID | FT_PEEK));
                answer($index, 'fetchmime', static fn () => imap_fetchmime($connection, $uid, $section, FT_UID | FT_PEEK));
                answer($index, 'bodystruct', static fn () => imap_bodystruct($connection, $msgno, $section));
            }
            break;

        case 'header':
            $value = hex2bin($item['value']);
            answer($index, 'mime_header_decode', static fn () => imap_mime_header_decode($value));
            answer($index, 'utf8', static fn () => imap_utf8($value));

            if ($item['name'] !== 'subject') {
                answer($index, 'parse_adrlist', static fn () => imap_rfc822_parse_adrlist($value, 'default.host'));
            }
            break;
    }
}

fclose($handle);
imap_close($connection);
imap_errors();
imap_alerts();
