<?php

namespace ImapPolyfill\Tests\Support;

/**
 * A folder name with characters outside ASCII, carried through every
 * operation that has to name it.
 *
 * Applications encode such a name to modified UTF-7 themselves — this is
 * what ddeboer/imap and Webklex both do — and then hand it to the imap_*
 * functions, so that is what is done here. What matters at each step is
 * whether the name survives the trip: a folder created under one spelling
 * and listed under another is a folder the caller cannot open again.
 *
 * Shared by the generator and the test so the steps a recorded answer came
 * from are the steps it is replayed with.
 */
final class FolderNameRoundTrip
{
    /**
     * Names worth carrying, in UTF-8 as an application would hold them.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        return [
            'ascii' => 'Plain',
            'accented latin' => 'Caffè',
            'mixed latin' => 'Zoë Doe',
            'cyrillic' => 'Привет',
            'cjk' => '你好',
            'symbol' => '€uro',
            'ampersand' => 'Black&White',
            'space' => 'Two Words',
        ];
    }

    /**
     * Runs one name through create, list, status, append, rename and
     * delete, recording what each step answered.
     *
     * @return array<string, mixed>
     */
    public static function carry(string $name, string $host, int $port, string $user, string $password): array
    {
        // The same flags GreenmailTestCase::flags() honours, so pointing
        // the suite at a server with a real certificate does not fail
        // here alone.
        $flags = getenv('IMAP_POLYFILL_TEST_FLAGS') ?: '/imap/novalidate-cert';
        $spec = static fn (string $folder = '') => sprintf('{%s:%d%s}%s', $host, $port, $flags, $folder);
        $encode = static fn (string $utf8): string => (string) mb_convert_encoding($utf8, 'UTF7-IMAP', 'UTF-8');

        $suffix = bin2hex(random_bytes(4));
        $created = $encode($name.$suffix);
        $renamed = $encode($name.$suffix.'Renamed');

        $connection = imap_open($spec(), $user, $password);
        imap_errors();

        $steps = [];

        $steps['create'] = FixtureExport::shape(imap_createmailbox($connection, $spec($created)));

        // The name as the server hands it back: byte-identical to what was
        // asked for, or the caller cannot ask for it again.
        $listed = imap_list($connection, $spec(), '*') ?: [];
        $steps['listed as given'] = FixtureExport::shape(in_array($spec($created), $listed, true));

        $mailboxes = imap_getmailboxes($connection, $spec(), '*') ?: [];
        $names = array_map(static fn (\stdClass $box): string => $box->name, $mailboxes);
        $steps['getmailboxes as given'] = FixtureExport::shape(in_array($spec($created), $names, true));

        $status = imap_status($connection, $spec($created), SA_MESSAGES);
        $steps['status'] = FixtureExport::shape($status !== false ? ($status->messages ?? null) : false);

        $steps['append'] = FixtureExport::shape(
            imap_append($connection, $spec($created), "Subject: Carried\r\nFrom: joe@example.com\r\n\r\nBody")
        );

        $opened = imap_open($spec($created), $user, $password);
        $steps['open'] = FixtureExport::shape($opened !== false);
        $steps['num_msg'] = FixtureExport::shape($opened !== false ? imap_num_msg($opened) : false);

        $steps['rename'] = FixtureExport::shape(imap_renamemailbox($connection, $spec($created), $spec($renamed)));

        $listed = imap_list($connection, $spec(), '*') ?: [];
        $steps['listed under the new name'] = FixtureExport::shape(in_array($spec($renamed), $listed, true));

        $steps['delete'] = FixtureExport::shape(imap_deletemailbox($connection, $spec($renamed)));

        return $steps;
    }

}
