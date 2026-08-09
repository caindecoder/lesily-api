<?php

namespace Database\Factories;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Companies\Entities\OwnershipTransferCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Companies\Enums\OwnershipTransferCaseStatus;
use App\Companies\Enums\OwnershipTransferCaseOutcome;

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

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OwnershipTransferCaseStatus::Verified,
            'verified_at' => now(),
            'verified_by' => User::factory(),
            'new_owner_user_id' => $attributes['new_owner_user_id'] ?? User::factory(),
            'verification_notes' => fake()->sentence(),
            'verification_artefacts' => [
                ['type' => 'id_document', 'reference' => fake()->uuid(), 'capturedAt' => now()->toISOString()],
            ],
        ]);
    }

    public function closedExecuted(): static
    {
        return $this->verified()->state([
            'status' => OwnershipTransferCaseStatus::Closed,
            'closed_outcome' => OwnershipTransferCaseOutcome::Executed,
            'executed_at' => now(),
            'executed_by' => User::factory(),
        ]);
    }

    public function closedRejected(): static
    {
        return $this->state([
            'status' => OwnershipTransferCaseStatus::Closed,
            'closed_outcome' => OwnershipTransferCaseOutcome::Rejected,
            'rejection_reason' => fake()->sentence(),
            'rejection_reason_code' => 'identity_failed',
        ]);
    }
}
