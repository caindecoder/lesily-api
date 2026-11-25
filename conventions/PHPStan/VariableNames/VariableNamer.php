<?php

namespace Conventions\PHPStan\VariableNames;

use Closure;
use App\Models\User;
use DateTimeInterface;
use PHPStan\Type\Type;
use PhpParser\Node\Param;
use Illuminate\Support\Str;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use PhpParser\Node\Expr\Variable;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use PHPStan\Reflection\ParameterReflection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coding-conventions §2.26: whether a variable's name fits what it holds.
 */
final class VariableNamer
{
    /** The roles a person may be named by; a new one is added to coding-conventions §2.26 first. */
    protected const array ROLE_WORDS = [
        'user', 'actor', 'admin', 'platformAdmin', 'owner', 'newOwner', 'member',
        'subject', 'invitee', 'inviter', 'recipient', 'requester', 'viewer',
    ];

    /**
     * A promoted parameter is left to the property it declares.
     *
     * @param  array<int, Param>  $params
     * @param  list<ParameterReflection>  $reflections
     * @return list<array{int, string}>|null
     */
    public static function misnamedParameters(array $params, array $reflections): ?array
    {
        $misnamed = [];

        foreach ($params as $position => $param) {
            if ($param->isPromoted() || ! $param->var instanceof Variable || ! is_string($param->var->name)) {
                continue;
            }

            $message = self::misnaming($reflections[$position]->getType(), $param->var->name);

            if ($message !== null) {
                $misnamed[] = [$param->getStartLine(), $message];
            }
        }

        return $misnamed === [] ? null : $misnamed;
    }

    /**
     * @return string|null the message for a misnamed variable, null when the name fits
     */
    public static function misnaming(Type $type, string $name): ?string
    {
        $type = TypeCombinator::removeNull($type);
        $classNames = $type->getObjectClassNames();

        if (count($classNames) !== 1 || $classNames[0] === Closure::class) {
            return null;
        }

        $shortName = class_basename($classNames[0]);
        $item = self::listItem($type);

        if ($classNames[0] === User::class || $item === User::class) {
            return self::isRoleWord($name, $item === User::class)
                ? null
                : sprintf('`%s $%s` is not a role word (coding-conventions §2.26).', $shortName, $name);
        }

        if ((new ObjectType(DateTimeInterface::class))->isSuperTypeOf($type)->yes()) {
            return preg_match('/[a-z](At|On|Date|Deadline|Until)$/', $name) === 1
                ? null
                : sprintf('`%s $%s` does not say what happened — end it in At, On, Date, Deadline or Until (coding-conventions §2.26).', $shortName, $name);
        }

        $expected = match (true) {
            self::isResponse($type) => 'response',
            $item !== null => Str::plural(self::typeName(class_basename($item))),
            default => self::typeName($shortName),
        };

        return self::endsIn($name, $expected)
            ? null
            : sprintf('`%s $%s` should be named `$%s` (coding-conventions §2.26).', $shortName, $name, $expected);
    }

    /**
     * The class a list or a query holds; its name is the plural of the item's.
     */
    protected static function listItem(Type $type): ?string
    {
        if ((new ObjectType(Collection::class))->isSuperTypeOf($type)->yes()) {
            return self::onlyClass($type->getTemplateType(Collection::class, 'TValue'));
        }

        if ((new ObjectType(Builder::class))->isSuperTypeOf($type)->yes()) {
            return self::onlyClass($type->getTemplateType(Builder::class, 'TModel'));
        }

        return null;
    }

    protected static function isResponse(Type $type): bool
    {
        return (new ObjectType(Response::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(TestResponse::class))->isSuperTypeOf($type)->yes();
    }

    /**
     * The bare Model is a template's bound: the item is unknown.
     */
    protected static function onlyClass(Type $type): ?string
    {
        $classNames = $type->getObjectClassNames();

        return count($classNames) === 1 && $classNames[0] !== Model::class ? $classNames[0] : null;
    }

    /**
     * The expected name itself, or a role word in front of it.
     */
    protected static function endsIn(string $name, string $expected): bool
    {
        return $name === $expected || preg_match('/^[a-z][a-zA-Z0-9]*'.preg_quote(ucfirst($expected), '/').'$/', $name) === 1;
    }

    protected static function typeName(string $shortName): string
    {
        return lcfirst(preg_replace('/(Record|Interface)$/', '', $shortName) ?? $shortName);
    }

    protected static function isRoleWord(string $name, bool $isList): bool
    {
        if ($isList) {
            return in_array($name, array_map(Str::plural(...), self::ROLE_WORDS), true);
        }

        return in_array($name, self::ROLE_WORDS, true) || preg_match('/^[a-z]+(?:[A-Z][a-z]*)*By$/', $name) === 1;
    }
}
