<?php

namespace Conventions\PHPStan\VariableNames;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClosureNode;
use PHPStan\Collectors\Collector;

/**
 * @implements Collector<InClosureNode, list<array{int, string}>>
 */
final class ClosureParametersCollector implements Collector
{
    public function getNodeType(): string
    {
        return InClosureNode::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        return VariableNamer::misnamedParameters(
            $node->getOriginalNode()->params,
            $node->getClosureType()->getParameters(),
        );
    }
}
