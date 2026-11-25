<?php

namespace Conventions\PHPStan\VariableNames;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PHPStan\Collectors\Collector;

/**
 * @implements Collector<Assign, list<array{int, string}>>
 */
final class AssignedLocalsCollector implements Collector
{
    public function getNodeType(): string
    {
        return Assign::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $node->var instanceof Variable || ! is_string($node->var->name)) {
            return null;
        }

        $message = VariableNamer::misnaming($scope->getType($node->expr), $node->var->name);

        return $message === null ? null : [[$node->getStartLine(), $message]];
    }
}
