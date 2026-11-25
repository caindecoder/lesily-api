<?php

namespace Conventions\PHPStan\VariableNames;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Foreach_;
use PHPStan\Collectors\Collector;

/**
 * @implements Collector<Foreach_, list<array{int, string}>>
 */
final class ForeachValuesCollector implements Collector
{
    public function getNodeType(): string
    {
        return Foreach_::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $node->valueVar instanceof Variable || ! is_string($node->valueVar->name)) {
            return null;
        }

        $type = $scope->getIterableValueType($scope->getType($node->expr));
        $message = VariableNamer::misnaming($type, $node->valueVar->name);

        return $message === null ? null : [[$node->valueVar->getStartLine(), $message]];
    }
}
