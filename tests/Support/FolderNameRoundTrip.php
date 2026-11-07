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
    public static function carry(string $name, string $base, string $user, string $password): array
    {
        $encode = static fn (string $utf8): string => (string) mb_convert_encoding($utf8, 'UTF7-IMAP', 'UTF-8');

        $suffix = bin2hex(random_bytes(4));
        $created = $encode($name.$suffix);
        $renamed = $encode($name.$suffix.'Renamed');

        $connection = imap_open($base, $user, $password);
        imap_errors();

        $steps = [];

        $steps['create'] = FixtureExport::shape(imap_createmailbox($connection, $base.$created));

        // The name as the server hands it back: byte-identical to what was
        // asked for, or the caller cannot ask for it again.
        $listed = imap_list($connection, $base, '*') ?: [];
        $steps['listed as given'] = FixtureExport::shape(in_array($base.$created, $listed, true));

        $mailboxes = imap_getmailboxes($connection, $base, '*') ?: [];
        $names = array_map(static fn (\stdClass $box): string => $box->name, $mailboxes);
        $steps['getmailboxes as given'] = FixtureExport::shape(in_array($base.$created, $names, true));

        $status = imap_status($connection, $base.$created, SA_MESSAGES);
        $steps['status'] = FixtureExport::shape($status !== false ? ($status->messages ?? null) : false);

        $steps['append'] = FixtureExport::shape(
            imap_append($connection, $base.$created, "Subject: Carried\r\nFrom: joe@example.com\r\n\r\nBody")
        );

        $opened = imap_open($base.$created, $user, $password);
        $steps['open'] = FixtureExport::shape($opened !== false);
        $steps['num_msg'] = FixtureExport::shape($opened !== false ? imap_num_msg($opened) : false);

        $steps['rename'] = FixtureExport::shape(imap_renamemailbox($connection, $base.$created, $base.$renamed));

        $listed = imap_list($connection, $base, '*') ?: [];
        $steps['listed under the new name'] = FixtureExport::shape(in_array($base.$renamed, $listed, true));

        $steps['delete'] = FixtureExport::shape(imap_deletemailbox($connection, $base.$renamed));

        return $steps;
    }

}
