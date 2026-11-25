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

    protected function message(string $declared, string $expected): string
    {
        return "`{$declared}` should be named `\${$expected}` (coding-conventions §2.26).";
    }

    protected function fixturePath(string $fixture): string
    {
        return __DIR__.'/Fixtures/VariableNames/'.$fixture;
    }
}
