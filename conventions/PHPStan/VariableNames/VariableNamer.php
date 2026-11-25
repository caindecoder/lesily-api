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
use Illuminate\Support\LazyCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use PHPStan\Reflection\ParameterReflection;
use Illuminate\Pagination\AbstractPaginator;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Pagination\AbstractCursorPaginator;

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

            $type = $reflections[$position]->getType();
            $message = $param->variadic
                ? self::variadicMisnaming($type, $param->var->name)
                : self::misnaming($type, $param->var->name);

            if ($message !== null) {
                $misnamed[] = [$param->getStartLine(), $message];
            }
        }

        return $misnamed === [] ? null : $misnamed;
    }

    /**
     * A variadic parameter holds a list of its declared type.
     */
    protected static function variadicMisnaming(Type $type, string $name): ?string
    {
        $className = self::onlyClass(TypeCombinator::removeNull($type));

        if ($className === null || $className === Closure::class) {
            return null;
        }

        $declared = class_basename($className).' ...$'.$name;

        return $className === User::class
            ? self::personMisnaming($declared, $name, true)
            : self::thingMisnaming($declared, $name, Str::plural(self::typeName(class_basename($className))));
    }

    /**
     * @return string|null the message for a misnamed variable, null when the name fits
     */
    public static function misnaming(Type $type, string $name): ?string
    {
        $type = TypeCombinator::removeNull($type);
        $classNames = $type->getObjectClassNames();

        if (count($classNames) !== 1 || $classNames[0] === Closure::class || self::isAnonymous($type)) {
            return null;
        }

        $declared = class_basename($classNames[0]).' $'.$name;
        $item = self::listItem($type);

        return match (true) {
            $classNames[0] === User::class || $item === User::class => self::personMisnaming($declared, $name, $item !== null),
            (new ObjectType(DateTimeInterface::class))->isSuperTypeOf($type)->yes() => self::dateMisnaming($declared, $name),
            default => self::thingMisnaming($declared, $name, self::expectedName($type, $classNames[0], $item)),
        };
    }

    protected static function personMisnaming(string $declared, string $name, bool $isList): ?string
    {
        return self::isRoleWord($name, $isList)
            ? null
            : "`{$declared}` is not a role word (coding-conventions §2.26).";
    }

    protected static function dateMisnaming(string $declared, string $name): ?string
    {
        return preg_match('/[a-z](At|On|Date|Deadline|Until)$/', $name) === 1
            ? null
            : "`{$declared}` does not say what happened — end it in At, On, Date, Deadline or Until (coding-conventions §2.26).";
    }

    protected static function thingMisnaming(string $declared, string $name, string $expected): ?string
    {
        return self::endsIn($name, $expected)
            ? null
            : "`{$declared}` should be named `\${$expected}` (coding-conventions §2.26).";
    }

    protected static function expectedName(Type $type, string $className, ?string $item): string
    {
        return match (true) {
            self::isResponse($type) => 'response',
            $item !== null => Str::plural(self::typeName(class_basename($item))),
            default => self::typeName(class_basename($className)),
        };
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

        if ((new ObjectType(AbstractPaginator::class))->isSuperTypeOf($type)->yes()) {
            return self::onlyClass($type->getTemplateType(AbstractPaginator::class, 'TValue'));
        }

        if ((new ObjectType(AbstractCursorPaginator::class))->isSuperTypeOf($type)->yes()) {
            return self::onlyClass($type->getTemplateType(AbstractCursorPaginator::class, 'TValue'));
        }

        if ((new ObjectType(LazyCollection::class))->isSuperTypeOf($type)->yes()) {
            return self::onlyClass($type->getTemplateType(LazyCollection::class, 'TValue'));
        }

        return null;
    }

    /**
     * An anonymous class has no name to give a variable.
     */
    protected static function isAnonymous(Type $type): bool
    {
        foreach ($type->getObjectClassReflections() as $classReflection) {
            if ($classReflection->isAnonymous()) {
                return true;
            }
        }

        return false;
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
