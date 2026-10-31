<?php

namespace Tests\Feature\Foundation;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Carbon\CarbonPeriod;
use ReflectionNamedType;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rules\In;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rules\Date;
use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Exists;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\BuiltinTypeCast;
use Spatie\LaravelData\Attributes\MapInputName;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationPath;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Conv-plan §2.24 — a command's ids are models, its closed sets enums, its
 * dates Carbon and its true/false flags cast bools. The known count falls to
 * 0 as the modules are typed.
 */
class CommandPropertiesAreTypedTest extends FoundationTestCase
{
    protected const int LEGACY_BOUND = 69;

    #[Test]
    public function when_the_commands_are_walked_then_the_untyped_properties_equal_the_known_count(): void
    {
        // Given every Data class under app/**/UseCases/Commands
        $commands = $this->commandClasses();

        // When their public properties are walked
        $untyped = $this->untypedProperties($commands);

        // Then the walk read the commands and found the known count
        $this->assertWalkedAndCounted($commands, $untyped);
    }

    /**
     * @return list<class-string<Data>>
     */
    protected function commandClasses(): array
    {
        $commands = [];

        foreach (File::allFiles(app_path()) as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (str_contains(str_replace('\\', '/', $file->getRelativePath()), 'UseCases/Commands')
                && is_subclass_of($class, Data::class)) {
                $commands[] = $class;
            }
        }

        return $commands;
    }

    /**
     * @param  list<class-string<Data>>  $commands
     * @return list<string>
     */
    protected function untypedProperties(array $commands): array
    {
        $untyped = [];

        foreach ($commands as $command) {
            $rules = $this->rulesOf($command);

            foreach ((new ReflectionClass($command))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() === $command) {
                    array_push($untyped, ...$this->breachesOf($property, $rules));
                }
            }
        }

        return $untyped;
    }

    /**
     * @param  class-string  $command
     * @return array<string, mixed>
     */
    protected function rulesOf(string $command): array
    {
        if (! method_exists($command, 'rules')) {
            return [];
        }

        $rules = new ReflectionMethod($command, 'rules');

        return $rules->getNumberOfParameters() === 0
            ? $rules->invoke(null)
            : $rules->invoke(null, new ValidationContext([], [], ValidationPath::create()));
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    protected function breachesOf(ReflectionProperty $property, array $rules): array
    {
        $type = $property->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : '';
        $items = $this->ruleItems($rules[$this->inputNameOf($property)] ?? []);

        $reason = $this->opaqueReasonOf($property);
        $unmarkedString = $typeName === 'string' && ($reason === null || trim($reason) === '');

        $checks = [
            'a string reference' => $unmarkedString
                && ($this->namesAReference($property) || $this->holdsAnExistenceRule($items)),
            'a closed set kept as a string' => $unmarkedString && $this->holdsAClosedSet($items),
            'an opaque string with no reason' => $reason !== null && trim($reason) === '',
            'a flag that is not a cast bool' => $this->holdsAFlag($items) && ! $this->isACastBool($property),
            'a date that is not Carbon' => $this->holdsADate([...$items, ...$this->nestedItems($property, $rules)])
                && ! in_array($typeName, [CarbonImmutable::class, CarbonPeriod::class], true),
            'a mapper outside the id shape' => $this->mapsOutsideTheIdShape($property, $typeName),
        ];

        $where = $property->getDeclaringClass()->getName().'::$'.$property->getName();

        return array_map(fn (string $check): string => "{$where} — {$check}", array_keys(array_filter($checks)));
    }

    /**
     * @return list<mixed>
     */
    protected function ruleItems(mixed $entry): array
    {
        return match (true) {
            is_array($entry) => array_values($entry),
            is_string($entry) => explode('|', $entry),
            default => [$entry],
        };
    }

    protected function inputNameOf(ReflectionProperty $property): string
    {
        $mapper = $property->getAttributes(MapInputName::class)[0] ?? null;

        return $mapper?->getArguments()[0] ?? $property->getName();
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return list<mixed>
     */
    protected function nestedItems(ReflectionProperty $property, array $rules): array
    {
        $nested = [];

        foreach ($rules as $key => $entry) {
            if (str_starts_with($key, $this->inputNameOf($property).'.')) {
                array_push($nested, ...$this->ruleItems($entry));
            }
        }

        return $nested;
    }

    protected function opaqueReasonOf(ReflectionProperty $property): ?string
    {
        $marker = $property->getAttributes(OpaqueString::class)[0] ?? null;

        return $marker?->newInstance()->reason;
    }

    protected function namesAReference(ReflectionProperty $property): bool
    {
        return preg_match('/(Id|By)$/', $property->getName()) === 1;
    }

    /**
     * @param  list<mixed>  $items
     */
    protected function holdsAnExistenceRule(array $items): bool
    {
        foreach ($items as $item) {
            if ($item instanceof Exists || (is_string($item) && str_starts_with($item, 'exists:'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $items
     */
    protected function holdsAClosedSet(array $items): bool
    {
        foreach ($items as $item) {
            $values = $this->inValuesOf($item);

            if ($item instanceof Enum || ($values !== null && $values !== ['false', 'true'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $items
     */
    protected function holdsAFlag(array $items): bool
    {
        foreach ($items as $item) {
            if ($item === 'boolean' || $this->inValuesOf($item) === ['false', 'true']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $items
     */
    protected function holdsADate(array $items): bool
    {
        foreach ($items as $item) {
            if ($item === 'date' || $item instanceof Date || (is_string($item) && str_starts_with($item, 'date_format:'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>|null
     */
    protected function inValuesOf(mixed $item): ?array
    {
        $text = $item instanceof In ? (string) $item : $item;

        if (! is_string($text) || ! str_starts_with($text, 'in:')) {
            return null;
        }

        $values = array_map(fn (string $value): string => trim($value, '"'), explode(',', substr($text, 3)));
        sort($values);

        return $values;
    }

    protected function isACastBool(ReflectionProperty $property): bool
    {
        $type = $property->getType();
        $cast = $property->getAttributes(WithCast::class)[0] ?? null;

        return $type instanceof ReflectionNamedType && $type->getName() === 'bool' && $type->allowsNull()
            && $cast?->getArguments() === [BuiltinTypeCast::class, 'bool'];
    }

    protected function mapsOutsideTheIdShape(ReflectionProperty $property, string $typeName): bool
    {
        if ($property->getAttributes(MapInputName::class) === []
            || str_ends_with($property->getDeclaringClass()->getName(), 'Filters')) {
            return false;
        }

        return ! (is_subclass_of($typeName, Model::class) && $this->inputNameOf($property) === $property->getName().'Id');
    }

    /**
     * @param  list<class-string<Data>>  $commands
     * @param  list<string>  $untyped
     */
    protected function assertWalkedAndCounted(array $commands, array $untyped): void
    {
        $this->assertNotEmpty($commands);
        $this->assertCount(self::LEGACY_BOUND, $untyped, implode(PHP_EOL, $untyped));
    }
}
