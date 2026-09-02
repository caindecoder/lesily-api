<?php

namespace Tests\Feature\Foundation;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
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
}
