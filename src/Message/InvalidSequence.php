<?php

namespace ImapPolyfill\Message;

/**
 * A message set c-client's mail_sequence() would refuse, carrying the
 * wording it refuses with. imap_fetch_overview() turns this into the empty
 * array plus that message on the error stack.
 */
final class InvalidSequence extends \RuntimeException
{
}
