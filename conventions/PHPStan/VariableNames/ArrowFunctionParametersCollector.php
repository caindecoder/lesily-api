<?php

namespace Conventions\PHPStan\VariableNames;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InArrowFunctionNode;

/**
 * @implements Collector<InArrowFunctionNode, list<array{int, string}>>
 */
final class ArrowFunctionParametersCollector implements Collector
{
    public function getNodeType(): string
    {
        return InArrowFunctionNode::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        return VariableNamer::misnamedParameters(
            $node->getOriginalNode()->params,
            $node->getClosureType()->getParameters(),
        );
    }
}
