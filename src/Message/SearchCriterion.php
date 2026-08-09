<?php

namespace ImapPolyfill\Message;

/**
 * One entry of a parsed criteria string: a keyword, and the value it took
 * where the keyword takes one.
 */
final class SearchCriterion
{
    public function __construct(
        public readonly SearchKey $key,
        public readonly ?string $argument = null,
    ) {
    }
}
