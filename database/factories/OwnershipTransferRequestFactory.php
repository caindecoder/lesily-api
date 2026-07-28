<?php

namespace Database\Factories;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Companies\OwnershipTransfers\Enums\TransitionSource;
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

    /**
     * An identity-verified request awaiting execution (SM-1-05 `Verified` —
     * still open, still counted by the one-open-per-company invariant).
     */
    public function verified(): static
    {
        $verifiedAt = now()->subHours(2);

        return $this->state(fn (): array => [
            'status' => OwnershipTransferStatus::Verified,
            'verified_at' => $verifiedAt,
            'verified_by' => User::factory(),
            'verification_notes' => 'Email confirmation + ID document verified.',
            'next_state_transition_at' => $verifiedAt->copy()->addDays(14),
        ]);
    }

    /**
     * A completed transfer (SM-1-05 `Executed`, terminal — timer cleared, the
     * forensic old/new-owner pair recorded). The exact execute-write semantics
     * land at Task 8; these are read-fixture defaults.
     */
    public function executed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OwnershipTransferStatus::Executed,
            'verified_at' => now()->subHours(3),
            'verified_by' => User::factory(),
            'executed_at' => now()->subHour(),
            'executed_by' => User::factory(),
            'old_owner_user_id' => $attributes['current_owner_user_id'],
            'new_owner_user_id' => $attributes['target_user_id'],
            'next_state_transition_at' => null,
        ]);
    }

    /**
     * A support-rejected transfer (SM-1-05 `Rejected`, terminal). Reject-write
     * semantics land at Task 5; these are read-fixture defaults.
     */
    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => OwnershipTransferStatus::Rejected,
            'rejected_at' => now()->subHour(),
            'rejected_by' => User::factory(),
            'rejection_reason' => 'Identity documents did not match the records.',
            // The literals were corrected to the hyphenated canon at s152, after
            // the underscore spelling turned out to describe a value no code path
            // could produce. They now come from the enum itself (ruling J6), so
            // the fixture cannot drift from production again — which is the
            // failure this comment used to narrate.
            'rejection_source' => TransitionSource::PreVerification->value,
            'rejection_reason_code' => 'identity_failed',
            'next_state_transition_at' => null,
        ]);
    }

    /**
     * An owner-withdrawn transfer (SM-1-05 `Cancelled`, terminal). Withdraw-write
     * semantics land at Task 6; these are read-fixture defaults.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OwnershipTransferStatus::Cancelled,
            'cancelled_at' => now()->subHour(),
            'cancelled_by' => $attributes['current_owner_user_id'],
            'cancellation_source' => TransitionSource::PreVerification->value,
            'cancellation_reason' => 'Owner changed their mind about the transfer.',
            'next_state_transition_at' => null,
        ]);
    }

    /**
     * A lapsed transfer (SM-1-05 `Expired`, terminal) — read-fixture defaults
     * modelling the T8 requested-stage window.
     *
     * `expiry_source` now comes from the enum, the last of the three `*_source`
     * literals to migrate (the other two moved at s152). It could only be done
     * once T8 existed to produce the case; until then the enum carried no expiry
     * member and a hand-written literal was the only option. Sourcing it from the
     * enum is right HERE, in a fixture that mirrors production — and wrong in an
     * ASSERTION, where reading the expected value from the same enum production
     * renders from makes the check unable to fail (s155 L1).
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => OwnershipTransferStatus::Expired,
            'expired_at' => now()->subHour(),
            'expiry_source' => TransitionSource::RequestedStage->value,
            'expired_after_days' => 30,
            'next_state_transition_at' => null,
        ]);
    }
}
