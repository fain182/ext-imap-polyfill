<?php

namespace ImapPolyfill\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Support\Diagnostics is the one place that installs a handler, so that
 * what reaches the caller's own handler is decided in one place.
 *
 * @implements Rule<FuncCall>
 */
final class ErrorHandlerOnlyInDiagnosticsRule implements Rule
{
    private const FUNCTIONS = ['set_error_handler', 'restore_error_handler'];

    private const OWNER = 'ImapPolyfill\Support\Diagnostics';

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        $function = strtolower($node->name->getLast());

        if (!in_array($function, self::FUNCTIONS, true) || $scope->getClassReflection()?->getName() === self::OWNER) {
            return [];
        }

        return [
            RuleErrorBuilder::message("{$function}() belongs in Support\\Diagnostics only.")
                ->identifier('imapPolyfill.errorHandler')
                ->build(),
        ];
    }
}
