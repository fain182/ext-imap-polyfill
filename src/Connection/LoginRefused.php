<?php

namespace ImapPolyfill\Connection;

/**
 * Every login trial refused. c-client logs each refusal as it comes and a
 * last line once the trials are spent, so the error stack holds one entry
 * per trial and that line after them: messages() is all of it, in order.
 */
final class LoginRefused extends \RuntimeException
{
    private const EXHAUSTED = 'Too many login failures';

    /**
     * @param non-empty-list<string> $refusals the server's text for each trial
     */
    public function __construct(private readonly array $refusals)
    {
        parent::__construct(self::EXHAUSTED);
    }

    /**
     * @return non-empty-list<string>
     */
    public function messages(): array
    {
        return [...$this->refusals, self::EXHAUSTED];
    }
}
