<?php

namespace Tests\Unit\Conventions;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Conventions\PHPStan\ModelKeyReadRule;

/**
 * Conv-plan §2.21: a model's key is read with getKey(), never ->id. The rule
 * reads the receiver's type, so a query-builder row or a plain object keeps
 * its ->id while a model, or a resource proxying one, is flagged.
 *
 * @extends RuleTestCase<ModelKeyReadRule>
 */
class ModelKeyReadsUseGetKeyTest extends RuleTestCase
{
    private const string MESSAGE = 'Read a model key with getKey(), not ->id (coding-conventions §2.21).';

    protected function getRule(): Rule
    {
        return new ModelKeyReadRule;
    }

    #[Test]
    public function when_a_model_key_is_read_with_id_then_the_read_is_flagged(): void
    {
        // Given a User read with ->id
        // When the rule analyses it
        // Then the read's line is reported
        $this->assertFlagsLines('ModelRead.php', 11);
    }

    #[Test]
    public function when_a_nullable_model_key_is_read_with_nullsafe_id_then_the_read_is_flagged(): void
    {
        // Given Role::findByCode(...)?->id
        // When the rule analyses it
        // Then the read's line is reported
        $this->assertFlagsLines('NullsafeModelRead.php', 11);
    }

    #[Test]
    public function when_a_resource_reads_its_model_key_through_the_proxy_then_the_read_is_flagged(): void
    {
        // Given a JsonResource emitting $this->id
        // When the rule analyses it
        // Then the read's line is reported
        $this->assertFlagsLines('ResourceProxyRead.php', 19);
    }

    #[Test]
    public function when_a_resource_reads_a_model_typed_resource_id_then_the_read_is_flagged(): void
    {
        // Given a resource whose $resource is a User, emitting $this->resource->id
        // When the rule analyses it
        // Then the read's line is reported
        $this->assertFlagsLines('ModelTypedResourceRead.php', 19);
    }

    #[Test]
    public function when_a_query_builder_row_id_is_read_then_it_passes(): void
    {
        // Given a stdClass row's ->id
        // When the rule analyses it
        // Then nothing is reported
        $this->assertPasses('RowRead.php');
    }

    #[Test]
    public function when_a_resource_over_a_row_reads_its_id_then_it_passes(): void
    {
        // Given a resource whose $resource is a stdClass row
        // When the rule analyses $this->resource->id
        // Then nothing is reported
        $this->assertPasses('RowResourceRead.php');
    }

    #[Test]
    public function when_a_plain_object_id_property_is_read_then_it_passes(): void
    {
        // Given spatie's StoredEvent, a plain object with a public id
        // When the rule analyses its ->id
        // Then nothing is reported
        $this->assertPasses('PlainObjectRead.php');
    }

    #[Test]
    public function when_a_model_key_is_written_then_it_passes(): void
    {
        // Given $user->id assigned, not read
        // When the rule analyses it
        // Then nothing is reported
        $this->assertPasses('ModelKeyWrite.php');
    }

    #[Test]
    public function when_a_model_key_is_read_with_get_key_then_it_passes(): void
    {
        // Given getKey() on a model, on a resource proxy and nullsafe
        // When the rule analyses them
        // Then nothing is reported
        $this->assertPasses('GetKeyRead.php');
    }

    protected function assertFlagsLines(string $fixture, int ...$lines): void
    {
        $this->analyse(
            [$this->fixturePath($fixture)],
            array_map(fn (int $line): array => [self::MESSAGE, $line], array_values($lines)),
        );
    }

    protected function assertPasses(string $fixture): void
    {
        $this->analyse([$this->fixturePath($fixture)], []);
    }

    private function fixturePath(string $fixture): string
    {
        return __DIR__.'/Fixtures/ModelKeyReads/'.$fixture;
    }
}
