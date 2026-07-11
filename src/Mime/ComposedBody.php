<?php

namespace ImapPolyfill\Mime;

/**
 * Mutable mirror of the c-client BODY struct fields that
 * imap_mail_compose() can populate, defaulting like mail_newbody()
 * (TYPETEXT / ENC7BIT). Internal to ComposedMessage.
 *
 * @internal
 */
final class ComposedBody
{
    public int $type = 0;

    public int $encoding = 0;

    public ?string $subtype = null;

    /** @var list<array{string, string}> attribute/value pairs, in output order */
    public array $parameters = [];

    public ?string $id = null;

    public ?string $description = null;

    public ?string $md5 = null;

    public ?string $dispositionType = null;

    /** @var list<array{string, string}> */
    public array $dispositionParameters = [];

    public string $contents = '';

    /** @var list<self> only ever populated on a TYPEMULTIPART top body */
    public array $parts = [];
}
