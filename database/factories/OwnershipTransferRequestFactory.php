<?php

namespace Database\Factories;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

/**
 * @extends Factory<OwnershipTransferRequest>
 */
class OwnershipTransferRequestFactory extends Factory
{
    protected $model = OwnershipTransferRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $requestedAt = now()->subDay();

        return [
            'company_id' => Company::factory(),
            'current_owner_user_id' => User::factory(),
            'target_user_id' => User::factory(),
            'status' => OwnershipTransferStatus::Requested,
            'request_channel' => 'app',
            'reason' => fake()->sentence(),
            'requested_at' => $requestedAt,
            'next_state_transition_at' => $requestedAt->copy()->addDays(30),
            'created_by' => fn (array $attributes) => $attributes['current_owner_user_id'],
        ];
    }

    /**
     * An open request awaiting verification (SM-1-05 `Requested` — the state
     * the one-open-per-company invariant counts).
     */
    public function requested(): static
    {
        return $this->state(fn (): array => [
            'status' => OwnershipTransferStatus::Requested,
        ]);
    }
}
