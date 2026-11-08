<?php

namespace ImapPolyfill\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ErrorSuppress;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * "@" only lowers error_reporting(), and a handler that ignores it still
 * sees the warning: the noise has to go through Support\Diagnostics.
 *
 * @implements Rule<ErrorSuppress>
 */
final class NoErrorSuppressionRule implements Rule
{
    public function getNodeType(): string
    {
        return ErrorSuppress::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return [
            RuleErrorBuilder::message('Do not use "@": call the builtin through Support\Diagnostics::discard() or ::collect().')
                ->identifier('imapPolyfill.errorSuppression')
                ->build(),
        ];
    }
}
