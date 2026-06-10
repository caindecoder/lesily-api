<?php

namespace Database\Factories;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Foundation\Billing\Enums\Addon;
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
}
