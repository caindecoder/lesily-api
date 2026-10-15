<?php

namespace Conventions\PHPStan;

use PhpParser\Node;
use PHPStan\Type\Type;
use PHPStan\Rules\Rule;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use PhpParser\Node\Identifier;
use PHPStan\Rules\RuleErrorBuilder;
use PhpParser\Node\Expr\PropertyFetch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Coding-conventions §2.21: a model's key is read with getKey(), not ->id.
 * A JsonResource forwards ->id to its model, so it counts as one. PHPStan
 * hands a `?->id` read to this rule as a PropertyFetch on the non-null type.
 *
 * @implements Rule<PropertyFetch>
 */
final class ModelKeyReadRule implements Rule
{
    private const array KEYED_TYPES = [Model::class, JsonResource::class];

    public function getNodeType(): string
    {
        return PropertyFetch::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->isIdRead($node, $scope) || ! $this->isKeyed($scope->getType($node->var))) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Read a model key with getKey(), not ->id (coding-conventions §2.21).')
                ->identifier('lesily.modelKeyRead')
                ->build(),
        ];
    }

    private function isIdRead(PropertyFetch $node, Scope $scope): bool
    {
        return $node->name instanceof Identifier
            && $node->name->toString() === 'id'
            && ! $scope->isInExpressionAssign($node);
    }

    private function isKeyed(Type $receiver): bool
    {
        foreach (self::KEYED_TYPES as $keyedClass) {
            if ((new ObjectType($keyedClass))->isSuperTypeOf($receiver)->yes()) {
                return true;
            }
        }

        return false;
    }
}
