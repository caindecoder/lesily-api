<?php

namespace Database\Factories;

use App\Companies\Entities\Company;
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
            'current_billing_plan' => BillingPlan::Free,
        ];
    }

    public function onPlan(BillingPlan $plan): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_billing_plan' => $plan,
        ]);
    }
}
