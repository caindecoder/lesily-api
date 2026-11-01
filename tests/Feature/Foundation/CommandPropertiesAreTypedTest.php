<?php

namespace Tests\Feature\Foundation;

use Stringable;
use ReflectionMethod;
use ReflectionProperty;
use Carbon\CarbonPeriod;
use ReflectionNamedType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Support\DataConfig;
use App\Foundation\UseCases\Casts\ModelCast;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Casts\BuiltinTypeCast;
use Illuminate\Validation\ValidationRuleParser;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationPath;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Conv-plan §2.24 — a command's ids are models, its closed sets enums, its
 * dates Carbon and its true/false flags cast bools.
 */
class CommandPropertiesAreTypedTest extends FoundationTestCase
{
    protected const int COMMANDS_AT_LEAST = 52;

    #[Test]
    public function when_the_commands_are_walked_then_no_property_is_untyped(): void
    {
        // Given every Data class under app/**/UseCases/Commands
        $commands = $this->commandClasses();

        // When their public properties are walked
        $untyped = $this->untypedProperties($commands);

        // Then the walk read the commands and found no untyped property
        $this->assertWalkedAndTyped($commands, $untyped);
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

            foreach (app(DataConfig::class)->getDataClass($command)->properties as $property) {
                array_push($untyped, ...$this->breachesOf($command, $property, $rules));
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
     * @param  class-string<Data>  $command
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    protected function breachesOf(string $command, DataProperty $property, array $rules): array
    {
        $reflection = new ReflectionProperty($command, $property->name);
        $type = $reflection->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : '';
        $inputName = $property->inputMappedName ?? $property->name;
        $parsed = $this->parsedRules($rules[$inputName] ?? []);
        $markerReason = $this->markerReasonOf($reflection);
        $opaque = $markerReason !== null && $markerReason !== '';

        $checks = [
            'a string reference' => ! $opaque && $typeName === 'string'
                && (preg_match('/(Id|By)$/', $property->name) === 1 || $this->holdsRule('Exists', $parsed)),
            'a closed set kept as a string' => ! $opaque && $typeName === 'string' && $this->holdsAClosedSet($parsed),
            'a marker without a reason' => $markerReason === '',
            'a flag that is not a cast bool' => $this->holdsAFlag($parsed)
                && ! ($typeName === 'bool' && $type->allowsNull() && $property->cast instanceof BuiltinTypeCast),
            'a date that is not Carbon' => ($this->holdsADate($parsed) && ! $this->isCarbon($typeName))
                || $this->holdsANestedDateNotCarbon($property, $rules),
            'a mapper outside the id shape' => $property->inputMappedName !== null
                && ! str_ends_with($property->className, 'Filters')
                && ! (is_subclass_of($typeName, Model::class) && $property->inputMappedName === $property->name.'Id'),
            'a model without ModelCast' => $this->holdsAModelWithoutCast($command, $property, $typeName),
        ];

        $where = $property->className.'::$'.$property->name;

        return array_map(fn (string $check): string => "{$where} — {$check}", array_keys(array_filter($checks)));
    }

    protected function markerReasonOf(ReflectionProperty $reflection): ?string
    {
        $markers = $reflection->getAttributes(OpaqueString::class);

        return $markers === [] ? null : trim($markers[0]->newInstance()->reason);
    }

    /**
     * @param  class-string  $command
     */
    protected function holdsAModelWithoutCast(string $command, DataProperty $property, string $typeName): bool
    {
        return method_exists($command, 'rules')
            && is_subclass_of($typeName, Model::class)
            && ! $property->cast instanceof ModelCast;
    }

    /**
     * @return list<array<mixed>>
     */
    protected function parsedRules(mixed $entry): array
    {
        $parsed = [];

        foreach (is_array($entry) ? $entry : [$entry] as $item) {
            if ($item instanceof Enum) {
                $parsed[] = ['Enum', []];
            } elseif (is_string($item) || $item instanceof Stringable) {
                foreach (explode('|', (string) $item) as $text) {
                    $parsed[] = ValidationRuleParser::parse($text);
                }
            }
        }

        return $parsed;
    }

    /**
     * A nested `prop.*.key` date counts against the item class's `key` property
     * when the property carries #[DataCollectionOf]; without one it is always listed.
     *
     * @param  array<string, mixed>  $rules
     */
    protected function holdsANestedDateNotCarbon(DataProperty $property, array $rules): bool
    {
        $inputName = $property->inputMappedName ?? $property->name;

        foreach ($rules as $key => $entry) {
            if (str_starts_with($key, $inputName.'.')
                && $this->holdsADate($this->parsedRules($entry))
                && ! $this->isCarbon($this->itemTypeOf($property, $key))) {
                return true;
            }
        }

        return false;
    }

    protected function itemTypeOf(DataProperty $property, string $key): string
    {
        /** @var class-string<Data>|null $itemClass */
        $itemClass = $property->type->dataClass;
        $field = Str::afterLast($key, '.');

        if ($itemClass === null || ! property_exists($itemClass, $field)) {
            return '';
        }

        $type = (new ReflectionProperty($itemClass, $field))->getType();

        return $type instanceof ReflectionNamedType ? $type->getName() : '';
    }

    protected function isCarbon(string $typeName): bool
    {
        return in_array($typeName, [CarbonImmutable::class, CarbonPeriod::class], true);
    }

    /**
     * @param  list<array<mixed>>  $parsed
     */
    protected function holdsRule(string $name, array $parsed): bool
    {
        return array_filter($parsed, fn (array $rule): bool => $rule[0] === $name) !== [];
    }

    /**
     * @param  list<array<mixed>>  $parsed
     */
    protected function holdsAClosedSet(array $parsed): bool
    {
        foreach ($parsed as [$name, $values]) {
            if ($name === 'Enum' || ($name === 'In' && ! $this->isTrueFalse($values))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<mixed>>  $parsed
     */
    protected function holdsAFlag(array $parsed): bool
    {
        foreach ($parsed as [$name, $values]) {
            if ($name === 'Boolean' || ($name === 'In' && $this->isTrueFalse($values))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<mixed>>  $parsed
     */
    protected function holdsADate(array $parsed): bool
    {
        return $this->holdsRule('Date', $parsed) || $this->holdsRule('DateFormat', $parsed);
    }

    protected function isTrueFalse(mixed $values): bool
    {
        if (! is_array($values)) {
            return false;
        }

        sort($values);

        return $values === ['false', 'true'];
    }

    /**
     * @param  list<class-string<Data>>  $commands
     * @param  list<string>  $untyped
     */
    protected function assertWalkedAndTyped(array $commands, array $untyped): void
    {
        $this->assertGreaterThanOrEqual(self::COMMANDS_AT_LEAST, count($commands));
        $this->assertSame([], $untyped, 'Untyped - type it, or mark a deliberate string #[OpaqueString(<why>)]:');
    }
}
