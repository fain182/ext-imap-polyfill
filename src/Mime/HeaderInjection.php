<?php

namespace ImapPolyfill\Mime;

/**
 * Raised while collecting imap_mail_compose() input when a header value
 * embeds a line break that isn't a legal fold, so composition can abort
 * with the same warning-and-false outcome as ext-imap. Internal to
 * ComposedMessage; never escapes to callers.
 *
 * @internal
 */
final class HeaderInjection extends \RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct("header injection attempt in {$field}");
    }
}
