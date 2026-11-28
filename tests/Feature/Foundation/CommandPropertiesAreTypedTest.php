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
use App\Foundation\UseCases\Commands\QueryCommand;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationPath;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Conv-plan §2.24 — a command's ids are models, its closed sets enums, its
 * dates Carbon and its true/false flags cast bools.
 */
class CommandPropertiesAreTypedTest extends FoundationTestCase
{
    protected const int COMMANDS_AT_LEAST = 75;

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

    #[Test]
    public function when_the_marked_strings_are_walked_without_their_markers_then_the_string_checks_fire(): void
    {
        // Given every Data class under app/**/UseCases/Commands
        $commands = $this->commandClasses();

        // When their #[OpaqueString] properties are walked as if unmarked
        $fired = $this->stringChecksFiredUnderMarkers($commands);

        // Then both string checks fire on real marked properties
        $this->assertSame(['a closed set kept as a string', 'a string reference'], $fired);
    }

    /**
     * @return list<class-string<Data>>
     */
    protected function commandClasses(): array
    {
        $commands = [];

        foreach (File::allFiles(app_path()) as $splFileInfo) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $splFileInfo->getRelativePathname());

            if (str_contains(str_replace('\\', '/', $splFileInfo->getRelativePath()), 'UseCases/Commands')
                && is_subclass_of($class, Data::class)) {
                $commands[] = $class;
            }
        }

        return [...$commands, ...$this->listItemClasses($commands)];
    }

    /**
     * The item class of every #[DataCollectionOf] list a command holds, and of every list
     * those items hold in turn, wherever it lives.
     *
     * @param  list<class-string<Data>>  $commands
     * @return list<class-string<Data>>
     */
    protected function listItemClasses(array $commands): array
    {
        $items = [];

        foreach ($commands as $command) {
            foreach (app(DataConfig::class)->getDataClass($command)->properties as $dataProperty) {
                /** @var class-string<Data>|null $item */
                $item = $dataProperty->type->dataClass;

                if ($item !== null && ! in_array($item, $commands, true)) {
                    $items[] = $item;
                }
            }
        }

        $items = array_values(array_unique($items));

        return $items === [] ? [] : [...$items, ...$this->listItemClasses([...$commands, ...$items])];
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

            foreach (app(DataConfig::class)->getDataClass($command)->properties as $dataProperty) {
                array_push($untyped, ...$this->breachesOf($command, $dataProperty, $rules));
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

        $reflectionMethod = new ReflectionMethod($command, 'rules');

        return $reflectionMethod->getNumberOfParameters() === 0
            ? $reflectionMethod->invoke(null)
            : $reflectionMethod->invoke(null, new ValidationContext([], [], ValidationPath::create()));
    }

    /**
     * @param  class-string<Data>  $command
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    protected function breachesOf(string $command, DataProperty $dataProperty, array $rules): array
    {
        $reflectionProperty = new ReflectionProperty($command, $dataProperty->name);
        $reflectionType = $reflectionProperty->getType();
        $typeName = $reflectionType instanceof ReflectionNamedType ? $reflectionType->getName() : '';
        $inputName = $dataProperty->inputMappedName ?? $dataProperty->name;
        $parsed = $this->parsedRules($rules[$inputName] ?? []);
        $markerReason = $this->markerReasonOf($reflectionProperty);
        $opaque = $markerReason !== null && $markerReason !== '';

        $checks = [
            ...array_fill_keys($opaque ? [] : $this->stringChecksOf($dataProperty, $typeName, $parsed), true),
            'a marker without a reason' => $markerReason === '',
            'a flag that is not a cast bool' => $this->holdsAFlag($parsed)
                && ! ($typeName === 'bool' && $reflectionType->allowsNull() && $dataProperty->cast instanceof BuiltinTypeCast),
            'a date that is not Carbon' => ($this->holdsADate($parsed) && ! $this->isCarbon($typeName))
                || $this->holdsANestedDateNotCarbon($dataProperty, $rules),
            'a mapper outside the id shape' => $dataProperty->inputMappedName !== null
                && ! is_a($dataProperty->className, QueryCommand::class, true)
                && ! (is_subclass_of($typeName, Model::class) && $dataProperty->inputMappedName === $dataProperty->name.'Id'),
            'a model without ModelCast' => $this->holdsAModelWithoutCast($command, $dataProperty, $typeName),
        ];

        $where = $dataProperty->className.'::$'.$dataProperty->name;

        return array_map(fn (string $check): string => "{$where} — {$check}", array_keys(array_filter($checks)));
    }

    /**
     * @param  list<array<mixed>>  $parsed
     * @return list<string>
     */
    protected function stringChecksOf(DataProperty $dataProperty, string $typeName, array $parsed): array
    {
        return array_keys(array_filter([
            'a string reference' => $typeName === 'string'
                && (preg_match('/(Id|By)$/', $dataProperty->name) === 1 || $this->holdsRule('Exists', $parsed)),
            'a closed set kept as a string' => $typeName === 'string' && $this->holdsAClosedSet($parsed),
        ]));
    }

    /**
     * @param  list<class-string<Data>>  $commands
     * @return list<string>
     */
    protected function stringChecksFiredUnderMarkers(array $commands): array
    {
        $fired = [];

        foreach ($commands as $command) {
            $rules = $this->rulesOf($command);

            foreach (app(DataConfig::class)->getDataClass($command)->properties as $dataProperty) {
                $reflectionProperty = new ReflectionProperty($command, $dataProperty->name);
                $reflectionType = $reflectionProperty->getType();

                if ($this->markerReasonOf($reflectionProperty) !== null && $reflectionType instanceof ReflectionNamedType) {
                    $parsed = $this->parsedRules($rules[$dataProperty->inputMappedName ?? $dataProperty->name] ?? []);
                    array_push($fired, ...$this->stringChecksOf($dataProperty, $reflectionType->getName(), $parsed));
                }
            }
        }

        $fired = array_values(array_unique($fired));
        sort($fired);

        return $fired;
    }

    protected function markerReasonOf(ReflectionProperty $reflectionProperty): ?string
    {
        $markers = $reflectionProperty->getAttributes(OpaqueString::class);

        return $markers === [] ? null : trim($markers[0]->newInstance()->reason);
    }

    /**
     * @param  class-string  $command
     */
    protected function holdsAModelWithoutCast(string $command, DataProperty $dataProperty, string $typeName): bool
    {
        return method_exists($command, 'rules')
            && is_subclass_of($typeName, Model::class)
            && ! $dataProperty->cast instanceof ModelCast;
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
    protected function holdsANestedDateNotCarbon(DataProperty $dataProperty, array $rules): bool
    {
        $inputName = $dataProperty->inputMappedName ?? $dataProperty->name;

        foreach ($rules as $key => $entry) {
            if (str_starts_with($key, $inputName.'.')
                && $this->holdsADate($this->parsedRules($entry))
                && ! $this->isCarbon($this->itemTypeOf($dataProperty, $key))) {
                return true;
            }
        }

        return false;
    }

    protected function itemTypeOf(DataProperty $dataProperty, string $key): string
    {
        /** @var class-string<Data>|null $itemClass */
        $itemClass = $dataProperty->type->dataClass;
        $field = Str::afterLast($key, '.');

        if ($itemClass === null || ! property_exists($itemClass, $field)) {
            return '';
        }

        $reflectionType = (new ReflectionProperty($itemClass, $field))->getType();

        return $reflectionType instanceof ReflectionNamedType ? $reflectionType->getName() : '';
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
