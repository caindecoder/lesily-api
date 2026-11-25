<?php

namespace Conventions\PHPStan\VariableNames;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InFunctionNode;
use PHPStan\Collectors\Collector;

/**
 * @implements Collector<InFunctionNode, list<array{int, string}>>
 */
final class FunctionParametersCollector implements Collector
{
    public function getNodeType(): string
    {
        return InFunctionNode::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        return VariableNamer::misnamedParameters(
            $node->getOriginalNode()->params,
            $node->getFunctionReflection()->getParameters(),
        );
    }
}
