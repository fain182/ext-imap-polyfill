<?php

/**
 * Writes the list of what crawl.php will ask about, one hex-encoded JSON
 * item per line:
 *
 *     php tests/realmail/snapshot.php .env.realmail > corpus
 *
 * The list is fixed before either engine runs because the two runs are
 * minutes apart against a mailbox that keeps receiving mail, and because
 * an engine must not pick what it is asked: if each side enumerated the
 * MIME sections from its own imap_fetchstructure(), a structure one of them
 * parsed wrong would change the questions instead of failing an answer.
 * So messages are named by uid, sections are listed here once, and header
 * values are copied here as the server sent them.
 */

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/account.php';

$account = realmailAccount($argv[1] ?? '.env.realmail');

function emit(array $item): void
{
    echo bin2hex(json_encode($item, JSON_THROW_ON_ERROR)), "\n";
}

/**
 * The section numbers imap_fetchbody() takes, in c-client's numbering: a
 * single-part body is "1", a multipart's children are numbered from 1 under
 * their parent, and a message/rfc822 part's own body is one level further
 * down ("2.1", or "2.1", "2.2" when it is multipart itself).
 *
 * @return list<string>
 */
function sections(object $part, string $prefix = ''): array
{
    if ($part->type !== TYPEMULTIPART) {
        return [$prefix === '' ? '1' : $prefix];
    }

    $found = [];

    foreach ($part->parts ?? [] as $position => $child) {
        $number = ($prefix === '' ? '' : $prefix.'.').($position + 1);
        $found[] = $number;

        if ($child->type === TYPEMULTIPART) {
            array_push($found, ...sections($child, $number));
        } elseif ($child->type === TYPEMESSAGE && isset($child->parts[0])) {
            $inner = $child->parts[0];
            array_push($found, ...($inner->type === TYPEMULTIPART ? sections($inner, $number) : [$number.'.1']));
        }
    }

    return $found;
}

/**
 * The header fields worth decoding on their own, unfolded, as raw as the
 * server sent them.
 *
 * @return list<array{string, string}>
 */
function headerFields(string $header): array
{
    $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $header);
    preg_match_all('/^(Subject|From|To|Cc|Reply-To|Sender|Return-Path):[ \t]*(.*?)\r?$/mi', $unfolded, $matches, PREG_SET_ORDER);

    return array_map(static fn (array $match): array => [ucfirst(strtolower($match[1])), $match[2]], $matches);
}

$connection = realmailOpen($account, $account['folders'][0]);
emit(['kind' => 'server']);

$seenHeaders = [];

foreach ($account['folders'] as $folder) {
    imap_reopen($connection, $account['server'].$folder, OP_READONLY);
    $count = imap_num_msg($connection);
    $first = max(1, $count - $account['limit'] + 1);

    if ($count === 0) {
        continue;
    }

    $uids = array_map(static fn (int $msgno): int => imap_uid($connection, $msgno), range($first, $count));
    emit(['kind' => 'folder', 'folder' => $folder, 'min' => min($uids), 'max' => max($uids)]);

    foreach ($uids as $uid) {
        $structure = imap_fetchstructure($connection, $uid, FT_UID);
        emit([
            'kind' => 'message',
            'folder' => $folder,
            'uid' => $uid,
            'sections' => $structure === false ? [] : sections($structure),
        ]);

        foreach (headerFields((string) imap_fetchheader($connection, $uid, FT_UID)) as [$name, $value]) {
            $key = $name."\0".$value;

            if (isset($seenHeaders[$key])) {
                continue;
            }

            $seenHeaders[$key] = true;
            emit(['kind' => 'header', 'name' => $name, 'value' => bin2hex($value)]);
        }
    }

    fwrite(STDERR, sprintf("%s: %d messages\n", $folder, count($uids)));
}

imap_close($connection);
imap_errors();
imap_alerts();
