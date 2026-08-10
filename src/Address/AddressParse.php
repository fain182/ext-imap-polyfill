<?php

namespace ImapPolyfill\Address;

/**
 * What one turn of an address list came to.
 *
 * c-client asks this as two questions — rfc822_parse_address() answers a
 * tail, and the caller then looks at whether the parse pointer is still
 * live before deciding what to do with what follows. They are two questions
 * about one outcome, and asking them separately is how a reader ends up
 * believing that "an address was read" and "there is more to read" are the
 * same answer.
 */
enum AddressParse
{
    /** An address was read, and the text after it is still to be looked at. */
    case Read;

    /**
     * The parse is over: something inside it gave up, having already said
     * why. Whether an address came out of this turn no longer matters —
     * neither caller reads any further, and neither adds a complaint of its
     * own on top of the one already made.
     */
    case Cancelled;

    /** Nothing here is an address, and the text is still there to be complained about. */
    case NotAnAddress;
}
