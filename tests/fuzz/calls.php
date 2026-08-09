<?php

/**
 * What each corpus input is fed to. One entry per function under the
 * oracle; the label is what compare.php reports and what the known
 * divergences below are keyed on.
 *
 * @return array<string, callable(string): mixed>
 */
return [
    'parse_adrlist' => static fn (string $input): mixed => imap_rfc822_parse_adrlist($input, 'default.host'),

    'parse_headers' => static fn (string $input): mixed => imap_rfc822_parse_headers($input),

    'utf8' => static fn (string $input): mixed => imap_utf8($input),

    'mime_header_decode' => static fn (string $input): mixed => imap_mime_header_decode($input),

    'qprint' => static fn (string $input): mixed => imap_qprint($input),

    'binary' => static fn (string $input): mixed => imap_binary($input),

    'base64' => static fn (string $input): mixed => imap_base64($input),

    'utf8_to_mutf7' => static fn (string $input): mixed => imap_utf8_to_mutf7($input),

    'mutf7_to_utf8' => static fn (string $input): mixed => imap_mutf7_to_utf8($input),

    // Not a function on its own but a round trip: what the parser read has
    // to be writable back. A divergence here is a divergence in either
    // half, which is worth knowing before it reaches a header on the wire.
    'write_address_round_trip' => static function (string $input): mixed {
        $parsed = imap_rfc822_parse_adrlist($input, 'default.host');
        $written = [];

        foreach ($parsed as $address) {
            if (!isset($address->mailbox, $address->host)) {
                continue;
            }

            // An absent personal is passed as the empty string, not as null:
            // null to a string parameter is the one difference no userland
            // function can answer (an internal one coerces it with a
            // deprecation, a userland one raises TypeError) and it would be
            // the only thing this call ever reported.
            $written[] = imap_rfc822_write_address($address->mailbox, $address->host, $address->personal ?? '');
        }

        return $written;
    },
];
