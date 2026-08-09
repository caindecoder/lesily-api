<?php

namespace Database\Factories;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Companies\Entities\OwnershipTransferCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Companies\Enums\OwnershipTransferCaseStatus;

/**
 * @extends Factory<OwnershipTransferCase>
 */
class OwnershipTransferCaseFactory extends Factory
{
    protected $model = OwnershipTransferCase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'status' => OwnershipTransferCaseStatus::Open,
            'requested_context' => fake()->sentence(),
            'new_owner_user_id' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(['company_id' => $company->getKey()]);
    }

    public function proposedOwner(User $user): static
    {
        return $this->state(['new_owner_user_id' => $user->getKey()]);
    }
}
