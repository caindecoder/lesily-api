<?php

namespace Conventions\PHPStan\VariableNames;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\ClassPropertyNode;

/**
 * @implements Collector<ClassPropertyNode, list<array{int, string}>>
 */
final class PropertiesCollector implements Collector
{
    public function getNodeType(): string
    {
        return ClassPropertyNode::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        $type = $node->getPhpDocType() ?? $node->getNativeType();
        $message = $type === null ? null : VariableNamer::misnaming($type, $node->getName());

        return $message === null ? null : [[$node->getStartLine(), $message]];
    }
}
