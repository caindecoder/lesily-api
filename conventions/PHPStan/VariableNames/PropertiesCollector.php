<?php

namespace Conventions\PHPStan\VariableNames;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use Spatie\LaravelData\Data;
use PHPStan\Collectors\Collector;
use PHPStan\Node\ClassPropertyNode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * A property that leaves the code keeps its name: every property of a
 * command or a queued class, and an event's public ones.
 *
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

        if ($type === null || $this->leavesTheCode($node)) {
            return null;
        }

        $message = VariableNamer::misnaming($type, $node->getName());

        return $message === null ? null : [[$node->getStartLine(), $message]];
    }

    protected function leavesTheCode(ClassPropertyNode $node): bool
    {
        $class = $node->getClassReflection();

        return $class->is(Data::class)
            || $class->implementsInterface(ShouldQueue::class)
            || ($node->isPublic() && $class->is(ShouldBeStored::class));
    }
}
