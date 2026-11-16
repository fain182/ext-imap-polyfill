<?php

namespace ImapPolyfill\Connection\Pop3;

/**
 * How much of a message has to come off the server: nothing, its header
 * (TOP n 0, or RETR where TOP is not advertised), or all of it (RETR).
 */
enum MessagePart
{
    case None;
    case Header;
    case Whole;
}
