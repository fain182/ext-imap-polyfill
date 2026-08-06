<?php

namespace ImapPolyfill\Connection;

/**
 * A uid/msgno translation found no such message. Distinct from a wire
 * failure because imap_msgno() answers 0 for it instead of pushing an error.
 */
final class MessageNotFoundException extends \RuntimeException
{
}
