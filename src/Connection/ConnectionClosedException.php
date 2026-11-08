<?php

namespace ImapPolyfill\Connection;

/**
 * The server hung up. Distinct from a refused command because imap_ping()
 * answers false for it without logging, and imap_reopen() dials again.
 */
final class ConnectionClosedException extends \RuntimeException
{
}
