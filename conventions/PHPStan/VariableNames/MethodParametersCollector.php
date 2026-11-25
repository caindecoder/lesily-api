<?php

namespace Conventions\PHPStan\VariableNames;

use BackedEnum;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use Spatie\LaravelData\Data;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ParameterReflection;
use Illuminate\Contracts\Routing\UrlRoutable;

/**
 * A parameter whose name a parent signature, Spatie's call to rules() or a
 * route binding fixes keeps that name.
 *
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
        if ($this->isSignatureFixed($node)) {
            return null;
        }

        $params = $node->getOriginalNode()->params;
        $reflections = $node->getMethodReflection()->getParameters();

        if (str_contains($node->getClassReflection()->getName(), '\\IO\\Http\\Controllers\\')) {
            $routeBound = array_keys(array_filter($reflections, $this->isRouteBound(...)));
            $params = array_diff_key($params, array_flip($routeBound));
        }

        return VariableNamer::misnamedParameters($params, $reflections);
    }

    protected function isSignatureFixed(InClassMethodNode $node): bool
    {
        $method = $node->getMethodReflection();
        $class = $node->getClassReflection();

        return $method->getPrototype()->getDeclaringClass()->getName() !== $class->getName()
            || ($method->getName() === 'rules' && $class->is(Data::class));
    }

    protected function isRouteBound(ParameterReflection $parameter): bool
    {
        $type = $parameter->getType();

        return (new ObjectType(UrlRoutable::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(BackedEnum::class))->isSuperTypeOf($type)->yes();
    }
}
