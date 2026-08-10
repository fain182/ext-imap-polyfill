<?php

namespace ImapPolyfill\Connection;

/**
 * The connection could not be established, or the stream broke in a way that
 * is not the server hanging up cleanly (ConnectionLostException is that one).
 *
 * The message is the reason alone — "Unable to connect to tcp://host:port
 * (Connection refused)" — with no host and port of the caller's own prefixed,
 * because c-client's imap_open() writes its "Can't connect to host,port: "
 * from the spec it was handed rather than from what the socket reported.
 */
final class ConnectionFailedException extends \RuntimeException
{
}
