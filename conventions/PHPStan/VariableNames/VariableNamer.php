<?php

namespace Conventions\PHPStan\VariableNames;

use PHPStan\Type\Type;

/**
 * Coding-conventions §2.26: whether a variable's name fits what it holds.
 */
final class VariableNamer
{
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
