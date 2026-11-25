<?php

namespace Conventions\PHPStan\VariableNames;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PhpParser\Node\Stmt\Catch_;
use PHPStan\Type\TypeCombinator;
use PhpParser\Node\Expr\Variable;
use PHPStan\Collectors\Collector;

/**
 * @implements Collector<Catch_, list<array{int, string}>>
 */
final class CaughtExceptionsCollector implements Collector
{
    public function getNodeType(): string
    {
        return Catch_::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $node->var instanceof Variable || ! is_string($node->var->name)) {
            return null;
        }

        $type = TypeCombinator::union(...array_map($scope->resolveTypeByName(...), $node->types));
        $message = VariableNamer::misnaming($type, $node->var->name);

        return $message === null ? null : [[$node->var->getStartLine(), $message]];
    }
}
