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
            // Hyphenated per ERD-1-05 §3, which names this literal specifically:
            // production writes 'pre-verification' from the aggregate, so the
            // underscore spelling here described a value no code path can produce
            // (pre-flight S2, deferred to "the projector lap" — that lap shipped
            // and this did not; caught at the s152 review).
            //
            // All THREE *_source literals in this file were corrected together,
            // superseding S2's "Tasks 6/7 own their halves" split. E68 §3 says
            // Boy-Scout THE FILE and audit the rest by volatility — deferring a
            // one-character fix sixteen lines down, inside a state() block already
            // being edited, is the one place that rule does not license. These are
            // read fixtures three merged test classes consume NOW, so leaving them
            // invites an assertion that bakes in a value production cannot emit,
            // which is precisely the defect being fixed one state up.
            'rejection_source' => 'pre-verification',
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
            'cancellation_source' => 'pre-verification',
            'cancellation_reason' => 'Owner changed their mind about the transfer.',
            'next_state_transition_at' => null,
        ]);
    }

    /**
     * A lapsed transfer (SM-1-05 `Expired`, terminal). Expiry-write semantics
     * land at Task 7; these are read-fixture defaults.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => OwnershipTransferStatus::Expired,
            'expired_at' => now()->subHour(),
            'expiry_source' => 'requested-stage',
            'expired_after_days' => 30,
            'next_state_transition_at' => null,
        ]);
    }
}
