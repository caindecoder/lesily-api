<?php

namespace Conventions\PHPStan\VariableNames;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PhpParser\Node\Expr\Variable;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassMethodNode;

/**
 * @implements Collector<InClassMethodNode, list<array{int, string}>>
 */
final class MethodParametersCollector implements Collector
{
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        $misnamed = [];
        $parameters = $node->getMethodReflection()->getParameters();

        foreach ($node->getOriginalNode()->params as $position => $param) {
            if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
                continue;
            }

            $message = VariableNamer::misnaming($parameters[$position]->getType(), $param->var->name);

            if ($message !== null) {
                $misnamed[] = [$param->getStartLine(), $message];
            }
        }

        return $misnamed === [] ? null : $misnamed;
    }
}
