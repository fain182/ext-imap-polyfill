<?php

namespace ImapPolyfill\Tests\Support;

/**
 * The states a connection can be in when nothing is there to answer with,
 * and the calls worth making in each.
 *
 * Shared by tests/fixtures/generate-unhappy-paths.php and
 * tests/Integration/UnhappyPathsTest.php so the two cannot drift: the
 * scenario a recorded outcome came from is the scenario it is replayed in.
 *
 * Each probe is one imap_* call on a connection that cannot satisfy it.
 * What matters is the pair — what comes back, and whether anything was
 * left on the error stack — because the two vary independently and both
 * are contract.
 */
final class UnhappyPaths
{
    /**
     * Builders for a connection in a state where the calls below have
     * nothing to work with.
     *
     * @return array<string, callable(string, int, string, string): (\IMAP\Connection|false)>
     */
    public static function scenarios(): array
    {
        return [
            // Nothing wrong with the connection: the folder is simply empty,
            // so every message number is past the end.
            'empty folder' => static function (string $host, int $port, string $user, string $password) {
                $spec = self::spec($host, $port);
                $folder = 'Unhappy'.bin2hex(random_bytes(5));
                $admin = imap_open($spec, $user, $password);
                imap_createmailbox($admin, $spec.$folder);

                return imap_open($spec.$folder, $user, $password);
            },

            // The folder went away under an open connection, so the server
            // itself will refuse what the client still thinks it can ask.
            'folder deleted underneath' => static function (string $host, int $port, string $user, string $password) {
                $spec = self::spec($host, $port);
                $folder = 'Unhappy'.bin2hex(random_bytes(5));
                $admin = imap_open($spec, $user, $password);
                imap_createmailbox($admin, $spec.$folder);
                $connection = imap_open($spec.$folder, $user, $password);
                imap_deletemailbox($admin, $spec.$folder);

                return $connection;
            },

            // Closed by the caller, and then used anyway.
            'closed connection' => static function (string $host, int $port, string $user, string $password) {
                $connection = imap_open(self::spec($host, $port), $user, $password);
                imap_close($connection);

                return $connection;
            },
        ];
    }

    /**
     * One call each, on whatever connection the scenario produced.
     *
     * @return array<string, callable(\IMAP\Connection): mixed>
     */
    public static function probes(): array
    {
        return [
            'imap_num_msg' => static fn ($c) => imap_num_msg($c),
            'imap_num_recent' => static fn ($c) => imap_num_recent($c),
            'imap_ping' => static fn ($c) => imap_ping($c),
            'imap_check' => static fn ($c) => imap_check($c),
            'imap_headers' => static fn ($c) => imap_headers($c),
            'imap_headerinfo' => static fn ($c) => imap_headerinfo($c, 1),
            'imap_fetchheader' => static fn ($c) => imap_fetchheader($c, 1),
            'imap_fetchbody' => static fn ($c) => imap_fetchbody($c, 1, '1'),
            'imap_fetchmime' => static fn ($c) => imap_fetchmime($c, 1, '1'),
            'imap_fetchstructure' => static fn ($c) => imap_fetchstructure($c, 1),
            'imap_body' => static fn ($c) => imap_body($c, 1),
            'imap_fetch_overview' => static fn ($c) => imap_fetch_overview($c, '1'),
            'imap_search' => static fn ($c) => imap_search($c, 'ALL'),
            'imap_sort' => static fn ($c) => imap_sort($c, SORTDATE, 0),
            'imap_uid' => static fn ($c) => imap_uid($c, 1),
            'imap_msgno' => static fn ($c) => imap_msgno($c, 1),
            'imap_setflag_full' => static fn ($c) => imap_setflag_full($c, '1', '\\Seen'),
            'imap_clearflag_full' => static fn ($c) => imap_clearflag_full($c, '1', '\\Seen'),
            'imap_delete' => static fn ($c) => imap_delete($c, 1),
            'imap_undelete' => static fn ($c) => imap_undelete($c, 1),
            'imap_expunge' => static fn ($c) => imap_expunge($c),
            'imap_mailboxmsginfo' => static fn ($c) => imap_mailboxmsginfo($c),
            'imap_gc' => static fn ($c) => imap_gc($c, IMAP_GC_ELT),
            'imap_is_open' => static fn ($c) => imap_is_open($c),
        ];
    }

    /**
     * Runs one probe and records what came back and what it left behind.
     * The error stack is drained first, so what is reported was put there
     * by this call and not by the scenario that set the connection up.
     *
     * @return array<string, mixed>
     */
    public static function outcome(callable $probe, \IMAP\Connection $connection): array
    {
        imap_errors();
        imap_alerts();

        try {
            $returned = FixtureExport::shape(@$probe($connection));
        } catch (\Throwable $e) {
            return ['throws' => $e::class, 'message' => $e->getMessage()];
        }

        return ['returns' => $returned, 'errors' => imap_errors() !== false];
    }

    /**
     * Return values are compared by shape, not by content: a header string
     * or a status object carries the server's own wording and the folder's
     * generated name, neither of which is the point here.
     */

    private static function spec(string $host, int $port): string
    {
        return sprintf('{%s:%d/imap/novalidate-cert}', $host, $port);
    }
}
