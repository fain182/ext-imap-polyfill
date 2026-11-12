<?php

/**
 * The account both scripts read, from an INI file kept out of git:
 *
 *     REALMAIL_SERVER = "{imap.fastmail.com:993/imap/ssl}"
 *     REALMAIL_USER = "someone@fastmail.com"
 *     REALMAIL_PASSWORD = "app-password"
 *     REALMAIL_FOLDERS = "INBOX"
 *     REALMAIL_LIMIT = 500
 *
 * A file rather than environment variables because the second run is in a
 * container: the repository is mounted there, the caller's environment is not.
 *
 * @return array{server: string, user: string, password: string, folders: list<string>, limit: int}
 */
function realmailAccount(string $path): array
{
    $values = is_file($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : false;

    if ($values === false) {
        fwrite(STDERR, "Cannot read the account file {$path}.\n");

        exit(2);
    }

    foreach (['REALMAIL_SERVER', 'REALMAIL_USER', 'REALMAIL_PASSWORD'] as $key) {
        if (($values[$key] ?? '') === '') {
            fwrite(STDERR, "{$path} has no {$key}.\n");

            exit(2);
        }
    }

    return [
        'server' => $values['REALMAIL_SERVER'],
        'user' => $values['REALMAIL_USER'],
        'password' => $values['REALMAIL_PASSWORD'],
        'folders' => array_values(array_filter(array_map(trim(...), explode(',', $values['REALMAIL_FOLDERS'] ?? 'INBOX')))),
        'limit' => (int) ($values['REALMAIL_LIMIT'] ?? 500),
    ];
}

/**
 * Every open is OP_READONLY, so the server is sent EXAMINE and refuses any
 * flag change: the crawl never calls a writing function, and this is what
 * would stop one if it did.
 *
 * @param array{server: string, user: string, password: string} $account
 */
function realmailOpen(array $account, string $folder): \IMAP\Connection
{
    $connection = imap_open($account['server'].$folder, $account['user'], $account['password'], OP_READONLY, 1);

    if ($connection === false) {
        fwrite(STDERR, 'Cannot open '.$account['server'].$folder.': '.implode(' / ', imap_errors() ?: [])."\n");

        exit(2);
    }

    return $connection;
}
