<?php

namespace Tests\Feature\Foundation;

use App\Companies\Entities\Company;
use App\Providers\AppServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\Addon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\MissingAttributeException;

/**
 * @testclass-carveout: infra-primitive — the Eloquent strict-mode contract
 * (conv-plan §2.19 R1) is provable only at the model seam; no HTTP walk can
 * pin which guard fired.
 */
class EloquentStrictModeRejectsUnsafeModelAccessTest extends FoundationTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_a_relation_is_read_lazily_on_a_model_from_a_multi_row_result_then_it_is_rejected(): void
    {
        // Given two companies hydrated together
        Company::factory()->count(2)->create();
        $companies = Company::query()->get();

        // When a relation is read lazily on one of them
        // Then the strict guard rejects the lazy load
        $this->expectException(LazyLoadingViolationException::class);

        $addons = $companies->firstOrFail()->addons;
    }

    #[Test]
    public function when_a_key_outside_fillable_is_mass_assigned_then_it_is_rejected(): void
    {
        // Given the company attributes plus one key the model does not fill
        /** @var array<string, mixed> $attributes */
        $attributes = Company::factory()->raw() + ['not_a_column' => 'discarded'];

        // When the company is mass-assigned
        // Then the strict guard rejects the silent discard
        $this->expectException(MassAssignmentException::class);

        Company::query()->create($attributes);
    }

    #[Test]
    public function when_an_attribute_the_model_never_loaded_is_read_then_it_is_rejected(): void
    {
        // Given a company fetched with only its key selected
        Company::factory()->create();
        $company = Company::query()->select('id')->firstOrFail();

        // When an unselected column is read
        // Then the strict guard rejects the missing attribute
        $this->expectException(MissingAttributeException::class);

        $name = $company->name;
    }

    #[Test]
    public function when_a_relation_is_read_lazily_on_a_model_fetched_alone_then_it_is_allowed(): void
    {
        // Given one company holding an add-on, fetched on its own
        $created = Company::factory()->withAddon(Addon::TemplateEngine)->create();
        $company = Company::query()->whereKey($created->getKey())->firstOrFail();

        // When the add-ons relation is read lazily on that single model
        $addons = $company->addons;

        // Then the framework's single-model exemption lets the read through
        $this->assertCount(1, $addons);
    }

    #[Test]
    public function when_the_environment_is_production_then_no_guard_is_armed(): void
    {
        // Given the application believes it is running in production
        $this->app->detectEnvironment(fn (): string => 'production');

        // When the provider boots
        (new AppServiceProvider($this->app))->boot();

        // Then none of the three guards is armed
        $this->assertGuardsArmed(false);
    }

    protected function assertGuardsArmed(bool $armed): void
    {
        $this->assertSame($armed, Model::preventsLazyLoading());
        $this->assertSame($armed, Model::preventsSilentlyDiscardingAttributes());
        $this->assertSame($armed, Model::preventsAccessingMissingAttributes());
    }
}
