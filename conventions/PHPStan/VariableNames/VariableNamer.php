<?php

namespace Conventions\PHPStan\VariableNames;

use App\Models\User;
use PHPStan\Type\Type;
use PhpParser\Node\Param;
use PhpParser\Node\Expr\Variable;
use PHPStan\Reflection\ParameterReflection;

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
        $classNames = $type->getObjectClassNames();

        if (count($classNames) !== 1) {
            return null;
        }

        $shortName = class_basename($classNames[0]);

        if ($classNames[0] === User::class) {
            return self::isRoleWord($name)
                ? null
                : sprintf('`%s $%s` is not a role word (coding-conventions §2.26).', $shortName, $name);
        }

        $expected = lcfirst(preg_replace('/(Record|Interface)$/', '', $shortName) ?? $shortName);

        return $expected === $name
            ? null
            : sprintf('`%s $%s` should be named `$%s` (coding-conventions §2.26).', $shortName, $name, $expected);
    }

    protected static function isRoleWord(string $name): bool
    {
        return in_array($name, self::ROLE_WORDS, true) || preg_match('/^[a-z]+(?:[A-Z][a-z]*)*By$/', $name) === 1;
    }
}
