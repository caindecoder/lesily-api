<?php

namespace Tests\Unit\Conventions;

use PHPStan\Rules\Rule;
use PHPStan\Collectors\Collector;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Conventions\PHPStan\VariableNameRule;

/**
 * Conv-plan §2.26: a person is named by their role, a thing by its type.
 *
 * @extends RuleTestCase<VariableNameRule>
 */
class VariablesAreNamedByRoleOrTypeTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new VariableNameRule;
    }

    protected function getCollectors(): array
    {
        return array_map(fn (string $collector): Collector => new $collector, VariableNameRule::COLLECTORS);
    }

    #[Test]
    public function when_a_parameter_is_not_named_after_its_type_then_it_is_flagged(): void
    {
        // Given a method taking RequestOrigin $origin
        // When the rule analyses it
        // Then the parameter is reported with its type's name
        $this->assertFlags('MisnamedParameter.php', [
            9 => $this->message('RequestOrigin $origin', 'requestOrigin'),
        ]);
    }

    #[Test]
    public function when_a_record_or_interface_type_is_named_with_its_suffix_then_it_is_flagged(): void
    {
        // Given BreachIncidentRecord $breachIncidentRecord and InvoiceRepositoryInterface $invoiceRepositoryInterface
        // When the rule analyses them
        // Then each is reported with its type's name, the suffix dropped
        $this->assertFlags('SuffixedTypes.php', [
            11 => $this->message('BreachIncidentRecord $breachIncidentRecord', 'breachIncident'),
            12 => $this->message('InvoiceRepositoryInterface $invoiceRepositoryInterface', 'invoiceRepository'),
        ]);
    }

    #[Test]
    public function when_a_property_is_not_named_after_its_type_then_it_is_flagged(): void
    {
        // Given a property RequestOrigin $origin
        // When the rule analyses it
        // Then the property is reported with its type's name
        $this->assertFlags('MisnamedProperty.php', [
            9 => $this->message('RequestOrigin $origin', 'requestOrigin'),
        ]);
    }

    #[Test]
    public function when_a_promoted_parameter_is_misnamed_then_it_is_flagged_once(): void
    {
        // Given a constructor promoting RequestOrigin $origin
        // When the rule analyses it
        // Then it is reported once, not as a parameter and again as a property
        $this->assertFlags('MisnamedPromotedParameter.php', [
            9 => $this->message('RequestOrigin $origin', 'requestOrigin'),
        ]);
    }

    #[Test]
    public function when_a_local_holds_an_inferred_type_under_another_name_then_it_is_flagged(): void
    {
        // Given $row assigned BreachIncidentRecord::query()->sole()
        // When the rule analyses it
        // Then the local is reported with the inferred type's name
        $this->assertFlags('MisnamedLocal.php', [
            11 => $this->message('BreachIncidentRecord $row', 'breachIncident'),
        ]);
    }

    #[Test]
    public function when_a_caught_exception_is_not_named_after_its_type_then_it_is_flagged(): void
    {
        // Given catch (QueryException $e)
        // When the rule analyses it
        // Then the caught exception is reported with its type's name
        $this->assertFlags('MisnamedCatch.php', [
            13 => $this->message('QueryException $e', 'queryException'),
        ]);
    }

    #[Test]
    public function when_a_foreach_value_is_not_named_after_its_type_then_it_is_flagged(): void
    {
        // Given foreach over a list of CompanyUser as $membership
        // When the rule analyses it
        // Then the value is reported with its type's name
        $this->assertFlags('MisnamedForeachValue.php', [
            16 => $this->message('CompanyUser $membership', 'companyUser'),
        ]);
    }

    #[Test]
    public function when_a_closure_parameter_is_not_named_after_its_type_then_it_is_flagged(): void
    {
        // Given a closure taking RequestOrigin $origin and an arrow function taking CompanyUser $membership
        // When the rule analyses them
        // Then each parameter is reported with its type's name
        $this->assertFlags('MisnamedClosureParameters.php', [
            13 => $this->message('RequestOrigin $origin', 'requestOrigin'),
            20 => $this->message('CompanyUser $membership', 'companyUser'),
        ]);
    }

    #[Test]
    public function when_a_function_parameter_is_not_named_after_its_type_then_it_is_flagged(): void
    {
        // Given a named function taking RequestOrigin $origin
        // When the rule analyses it
        // Then the parameter is reported with its type's name
        $this->assertFlags('MisnamedFunctionParameter.php', [
            7 => $this->message('RequestOrigin $origin', 'requestOrigin'),
        ]);
    }

    #[Test]
    public function when_a_person_is_named_outside_the_role_list_then_it_is_flagged(): void
    {
        // Given User $caller, User $currentUser and User $authUser
        // When the rule analyses them
        // Then each is reported as not a role word
        $this->assertFlags('UnlistedPeople.php', [
            10 => $this->notARole('User $caller'),
            11 => $this->notARole('User $currentUser'),
            12 => $this->notARole('User $authUser'),
        ]);
    }

    #[Test]
    public function when_a_date_does_not_say_what_happened_then_it_is_flagged(): void
    {
        // Given CarbonInterface $at and CarbonImmutable $asOf
        // When the rule analyses them
        // Then each is reported as a date that does not say what happened
        $this->assertFlags('UnnamedDates.php', [
            11 => $this->unnamedDate('CarbonInterface $at'),
            12 => $this->unnamedDate('CarbonImmutable $asOf'),
        ]);
    }

    #[Test]
    public function when_a_list_or_query_is_not_the_plural_of_its_item_then_it_is_flagged(): void
    {
        // Given Collection<int, CompanyUser> $members and Builder<BreachIncidentRecord> $query
        // When the rule analyses them
        // Then each is reported with the plural of its item's name
        $this->assertFlags('UnpluralLists.php', [
            17 => $this->message('Collection $members', 'companyUsers'),
            18 => $this->message('Builder $query', 'breachIncidents'),
        ]);
    }

    #[Test]
    public function when_a_nullable_parameter_is_misnamed_then_it_is_flagged(): void
    {
        // Given ?RequestOrigin $origin
        // When the rule analyses it
        // Then the parameter is reported with its class's name
        $this->assertFlags('MisnamedNullable.php', [
            9 => $this->message('RequestOrigin $origin', 'requestOrigin'),
        ]);
    }

    #[Test]
    public function when_a_role_word_stands_before_the_type_name_then_it_passes(): void
    {
        // Given PersonalAccessToken $currentPersonalAccessToken and Collection<int, CompanyUser> $activeCompanyUsers
        // When the rule analyses them
        // Then nothing is reported
        $this->assertPasses('PrefixedTypeNames.php');
    }

    #[Test]
    public function when_a_person_is_named_from_the_role_list_then_it_passes(): void
    {
        // Given a User named by every listed role word, a …By name, and a list of them by a plural role word
        // When the rule analyses them
        // Then nothing is reported
        $this->assertPasses('ListedPeople.php');
    }

    #[Test]
    public function when_a_date_ends_in_a_listed_suffix_then_it_passes(): void
    {
        // Given dates named $filedAt, $startsOn, $dueDate, $notifyDeadline and $validUntil
        // When the rule analyses them
        // Then nothing is reported
        $this->assertPasses('NamedDates.php');
    }

    #[Test]
    public function when_a_nullable_type_is_named_after_its_class_then_it_passes(): void
    {
        // Given ?RequestOrigin $requestOrigin and a ->first() local named $companyUser
        // When the rule analyses them
        // Then nothing is reported
        $this->assertPasses('NullableTypes.php');
    }

    #[Test]
    public function when_a_parent_signature_fixes_the_name_then_it_passes(): void
    {
        // Given a command's rules(ValidationContext $context), a Cast's cast(DataProperty $property, …), and a route-bound ResettableSetting $setting
        // When the rule analyses them
        // Then nothing is reported
        $this->assertPasses('ParentFixedRules.php', 'InterfaceFixedCast.php', 'IO/Http/Controllers/RouteBoundController.php');
    }

    #[Test]
    public function when_a_variable_holds_a_response_then_it_passes(): void
    {
        // Given TestResponse $response and JsonResponse $response
        // When the rule analyses them
        // Then nothing is reported
        $this->assertPasses('Responses.php');
    }

    #[Test]
    public function when_a_property_leaves_the_code_then_it_passes(): void
    {
        // Given a command's, an event's public and a queued class's property RequestOrigin $origin
        // When the rule analyses them
        // Then nothing is reported
        $this->assertPasses('LeavingCommand.php', 'LeavingEvent.php', 'LeavingQueuedClass.php');
    }

    #[Test]
    public function when_the_type_is_outside_the_rule_then_any_name_passes(): void
    {
        // Given a string, a Closure, a callable, mixed, object, a union of two classes and a bare Builder
        // When the rule analyses them
        // Then nothing is reported
        $this->assertPasses('OutsideTypes.php');
    }

    #[Test]
    public function when_every_variable_is_named_by_role_or_type_then_nothing_is_flagged(): void
    {
        // Given a property, promoted properties, parameters, locals, a foreach value and a caught exception, each named by role or type
        // When the rule analyses them
        // Then nothing is reported
        $this->assertPasses('WellNamed.php');
    }

    /**
     * @param  array<int, string>  $expected
     */
    protected function assertFlags(string $fixture, array $expected): void
    {
        $this->analyse(
            [$this->fixturePath($fixture)],
            array_map(fn (int $line, string $message): array => [$message, $line], array_keys($expected), $expected),
        );
    }

    protected function assertPasses(string ...$fixtures): void
    {
        $this->analyse(array_map($this->fixturePath(...), $fixtures), []);
    }

    protected function message(string $declared, string $expected): string
    {
        return "`{$declared}` should be named `\${$expected}` (coding-conventions §2.26).";
    }

    protected function notARole(string $declared): string
    {
        return "`{$declared}` is not a role word (coding-conventions §2.26).";
    }

    protected function unnamedDate(string $declared): string
    {
        return "`{$declared}` does not say what happened — end it in At, On, Date, Deadline or Until (coding-conventions §2.26).";
    }

    protected function fixturePath(string $fixture): string
    {
        return __DIR__.'/Fixtures/VariableNames/'.$fixture;
    }
}
