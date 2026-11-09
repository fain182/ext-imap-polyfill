<?php

namespace ImapPolyfill\Connection;

/**
 * One folder a LIST or LSUB reported, as c-client hands it to mm_list() and
 * mm_lsub(): one per response, so a name the server lists twice is listed
 * twice.
 */
final class ListedFolder
{
    /**
     * @param ?string      $delimiter  null where the server answered NIL, a
     *                                 flat namespace: no character at all,
     *                                 which php_imap.c reports differently
     *                                 from any character
     * @param list<string> $attributes the attribute names as the server
     *                                 spelled them, unknown ones included
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $delimiter,
        public readonly array $attributes,
    ) {
    }
}
