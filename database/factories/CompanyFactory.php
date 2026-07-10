<?php

namespace Database\Factories;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Companies\Enums\CompanyStatus;
use App\Foundation\Billing\Enums\Addon;
use App\Companies\Entities\CompanySettings;
use App\Foundation\Billing\Enums\BillingPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'br_number' => fake()->unique()->numerify('PV-########'),
            'epf_number' => fake()->unique()->numerify('EPF-#######'),
            'country' => 'LK',
            'owner_id' => User::factory(),
            'current_billing_plan' => BillingPlan::Free,
        ];
    }

    /**
     * A company whose 30-day trial has lapsed (Day-31 read-only state). A
     * trial-expired company has never subscribed, so its plan is NULL by
     * construction (ERD-1-02) — the state encodes both for realism.
     */
    public function trialExpired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CompanyStatus::TrialExpired,
            'current_billing_plan' => null,
        ]);
    }

    public function named(string $name): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => $name,
        ]);
    }

    public function onPlan(BillingPlan $plan): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_billing_plan' => $plan,
        ]);
    }

    /**
     * A company that has not chosen a plan yet (e.g. on trial) — the billing gate
     * must treat the absent plan as granting no plan-tier features.
     */
    public function withoutPlan(): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_billing_plan' => null,
        ]);
    }

    public function withAddon(Addon $addon): static
    {
        return $this->afterCreating(function (Company $company) use ($addon): void {
            $company->addons()->create(['addon' => $addon]);
        });
    }

    /**
     * A company with its settings row — production's genesis invariant (every
     * company gets one, seeded from CountryDefaults by CompanySettingsProjector).
     * Required by any fixture whose response renders the settings sub-object
     * (UC-1-032 CompanyResource).
     */
    public function withSettings(): static
    {
        return $this->afterCreating(function (Company $company): void {
            CompanySettings::factory()->create(['company_id' => $company->getKey()]);
        });
    }

    public function registeredAs(string $brNumber, string $epfNumber): static
    {
        return $this->state(fn (array $attributes): array => [
            'br_number' => $brNumber,
            'epf_number' => $epfNumber,
        ]);
    }
}
