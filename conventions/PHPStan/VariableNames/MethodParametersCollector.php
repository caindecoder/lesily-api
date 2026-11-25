<?php

namespace Conventions\PHPStan\VariableNames;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
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
        return VariableNamer::misnamedParameters(
            $node->getOriginalNode()->params,
            $node->getMethodReflection()->getParameters(),
        );
    }
}
