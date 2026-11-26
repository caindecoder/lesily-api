<?php

namespace Conventions\PHPStan\VariableNames;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use Spatie\LaravelData\Data;
use PHPStan\Collectors\Collector;
use PHPStan\Node\ClassPropertyNode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\EventHandler;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * A property that leaves the code keeps its name: every property of a
 * command or of a queued class the queue stores (a queued event handler is
 * built afresh by the worker, so its properties stay in the code), and an
 * event's public ones.
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

    protected function leavesTheCode(ClassPropertyNode $classPropertyNode): bool
    {
        $classReflection = $classPropertyNode->getClassReflection();

        return $classReflection->is(Data::class)
            || ($classReflection->implementsInterface(ShouldQueue::class) && ! $classReflection->implementsInterface(EventHandler::class))
            || ($classPropertyNode->isPublic() && $classReflection->is(ShouldBeStored::class));
    }
}
