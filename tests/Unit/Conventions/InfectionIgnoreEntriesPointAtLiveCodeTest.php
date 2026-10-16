<?php

namespace Tests\Unit\Conventions;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Conventions\Infection\DeadIgnoreEntries;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\Conventions\Fixtures\InfectionIgnores\IgnoreTargets;

/**
 * Conv-plan §2.7: an infection.json5 ignore entry names live code. Infection
 * never reports an entry that matches nothing, so a renamed method or a moved
 * line would silently stop ignoring its mutant; a line key must land on a
 * construct of its mutator's kind, the same start-line match Infection makes.
 */
class InfectionIgnoreEntriesPointAtLiveCodeTest extends TestCase
{
    private const string TARGETS = IgnoreTargets::class;

    private DeadIgnoreEntries $deadEntries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deadEntries = new DeadIgnoreEntries;
    }

    #[Test]
    public function when_an_entry_names_a_missing_class_then_it_is_reported(): void
    {
        // Given an entry for a class that does not exist
        // When the ledger is checked
        // Then the entry is reported
        $this->assertReported(
            ['CastInt' => ['App\\NoSuchClass']],
            'CastInt App\\NoSuchClass: class not found',
        );
    }

    #[Test]
    public function when_an_entry_names_a_missing_method_then_it_is_reported(): void
    {
        // Given an entry for a method the class does not declare
        // When the ledger is checked
        // Then the entry is reported
        $this->assertReported(
            ['CastInt' => [self::TARGETS.'::noSuchMethod']],
            'CastInt '.self::TARGETS.'::noSuchMethod: method not found',
        );
    }

    #[Test]
    public function when_a_line_key_lands_on_a_line_without_a_construct_then_it_is_reported(): void
    {
        // Given a CastInt key on the comment line above the cast it meant
        // When the ledger is checked
        // Then the entry is reported
        $this->assertReported(
            ['CastInt' => [self::TARGETS.'::constructs::14']],
            'CastInt '.self::TARGETS.'::constructs::14: no CastInt construct starts on that line',
        );
    }

    #[Test]
    public function when_a_line_key_lands_on_another_mutators_construct_then_it_is_reported(): void
    {
        // Given a CastInt key on the line of a string cast
        // When the ledger is checked
        // Then the entry is reported
        $this->assertReported(
            ['CastInt' => [self::TARGETS.'::constructs::21']],
            'CastInt '.self::TARGETS.'::constructs::21: no CastInt construct starts on that line',
        );
    }

    #[Test]
    public function when_a_line_key_names_a_mutator_the_check_does_not_know_then_it_is_reported(): void
    {
        // Given a line key for a mutator with no construct mapped
        // When the ledger is checked
        // Then the entry is reported, so the mapping is added before the key ships
        $this->assertReported(
            ['PublicVisibility' => [self::TARGETS.'::constructs::12']],
            'PublicVisibility '.self::TARGETS.'::constructs::12: no line check for this mutator',
        );
    }

    #[Test]
    public function when_entries_name_a_live_class_and_method_without_a_line_then_nothing_is_reported(): void
    {
        // Given a class-level and a method-level entry for live code
        // When the ledger is checked
        // Then nothing is reported - no line to match
        $this->assertReported(
            ['PublicVisibility' => [self::TARGETS, self::TARGETS.'::reset']],
        );
    }

    #[Test]
    #[DataProvider('constructLines')]
    public function when_a_line_key_lands_on_its_mutators_construct_then_nothing_is_reported(string $mutator, int $line): void
    {
        // Given a line key on a construct of its mutator's kind
        // When the ledger is checked
        // Then nothing is reported
        $this->assertReported(
            [$mutator => [self::TARGETS.'::constructs::'.$line]],
        );
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function constructLines(): array
    {
        return [
            'an array' => ['ArrayItemRemoval', 19],
            'a coalescing assignment' => ['AssignCoalesce', 15],
            'an int cast' => ['CastInt', 20],
            'a string cast' => ['CastString', 21],
            'an integer to decrement' => ['DecrementInteger', 15],
            'an integer to increment' => ['IncrementInteger', 15],
            'a method-call statement' => ['MethodCallRemoval', 17],
            'a nullsafe method call' => ['NullSafeMethodCall', 16],
        ];
    }

    #[Test]
    public function when_the_project_ledger_is_checked_then_every_entry_points_at_live_code(): void
    {
        // Given infection.json5
        // When its ignore entries are checked
        // Then none is dead
        $this->assertSame([], $this->deadEntries->in(dirname(__DIR__, 3).'/infection.json5'));
    }

    /**
     * @param  array<string, list<string>>  $ignoresByMutator
     */
    protected function assertReported(array $ignoresByMutator, string ...$expected): void
    {
        $this->assertSame(array_values($expected), $this->deadEntries->among($ignoresByMutator));
    }
}
