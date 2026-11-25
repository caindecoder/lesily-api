<?php

namespace Conventions\PHPStan\VariableNames;

use PHPStan\Type\Type;
use PhpParser\Node\Param;
use PhpParser\Node\Expr\Variable;
use PHPStan\Reflection\ParameterReflection;

/**
 * Coding-conventions §2.26: whether a variable's name fits what it holds.
 */
final class VariableNamer
{
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
        $shortName = self::shortName($type);

        if ($shortName === null) {
            return null;
        }

        $expected = lcfirst(preg_replace('/(Record|Interface)$/', '', $shortName) ?? $shortName);

        return $expected === $name
            ? null
            : sprintf('`%s $%s` should be named `$%s` (coding-conventions §2.26).', $shortName, $name, $expected);
    }

    protected static function shortName(Type $type): ?string
    {
        $classNames = $type->getObjectClassNames();

        if (count($classNames) !== 1) {
            return null;
        }

        $parts = explode('\\', $classNames[0]);

        return end($parts);
    }
}
