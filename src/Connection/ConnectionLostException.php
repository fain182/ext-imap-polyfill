<?php

namespace ImapPolyfill\Connection;

/**
 * The server hung up: a read found EOF where a response was due.
 *
 * Its own type because the layer above answers it rather than reports it.
 * imap_ping() calls a dead stream its return value — c-client returns NIL and
 * logs nothing — and imap_reopen() dials the host again and logs back in,
 * which is what lets a session outlive a server that drops idle connections.
 * Every other broken-stream case is ConnectionFailedException.
 */
final class ConnectionLostException extends \RuntimeException
{
}
