<?php

namespace App\Companies\OwnershipTransfers\IO\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use App\Companies\Entities\Company;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

/**
 * The platform-admin transfer shape (UC-1-127). A SIBLING of the owner-facing
 * OwnershipTransferRequestResource, never a subclass or a flagged variant: the
 * two serve different actors and will change for different reasons, so their
 * overlap is accidental duplication and must not be unified (E49; Advanced E03
 * §13). Exposes what the owner resource withholds — verification artefacts,
 * verifier notes, admin attribution, the forensic old/new-owner pair, raw ids,
 * and the target's REAL email.
 *
 * Carries no derived SLA fields (is_overdue / days_remaining / queue_position):
 * nothing consumes them yet (E49 §6 YAGNI), and when something does they are
 * presenter derivations, not response-model state (Advanced E11 §8 — the shape
 * LeaveDates' TrialManagement uses).
 *
 * @property-read OwnershipTransferRequest $resource
 */
class OwnershipTransferRequestAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'company_id' => $this->resource->company_id,
            'company' => $this->companySummary($this->resource->company),
            'status' => $this->resource->status,
            'request_channel' => $this->resource->request_channel,
            'reason' => $this->resource->reason,
            'requested_at' => $this->resource->requested_at,
            'requested_by' => $this->userSummary($this->resource->currentOwner),
            'target_user' => $this->userSummary($this->resource->targetUser),
            'verified_at' => $this->resource->verified_at,
            'verified_by' => $this->resource->verified_by,
            'verification_notes' => $this->resource->verification_notes,
            'verification_artefacts' => $this->resource->verification_artefacts,
            'executed_at' => $this->resource->executed_at,
            'executed_by' => $this->resource->executed_by,
            'old_owner_user_id' => $this->resource->old_owner_user_id,
            'new_owner_user_id' => $this->resource->new_owner_user_id,
            'rejected_at' => $this->resource->rejected_at,
            'rejected_by' => $this->resource->rejected_by,
            'rejection_reason' => $this->resource->rejection_reason,
            'rejection_source' => $this->resource->rejection_source,
            'cancelled_at' => $this->resource->cancelled_at,
            'cancelled_by' => $this->resource->cancelled_by,
            'cancellation_source' => $this->resource->cancellation_source,
            'cancellation_reason' => $this->resource->cancellation_reason,
            'expired_at' => $this->resource->expired_at,
            'expiry_source' => $this->resource->expiry_source,
            'expired_after_days' => $this->resource->expired_after_days,
            'next_state_transition_at' => $this->resource->next_state_transition_at,
        ];
    }

    /**
     * The tenant an admin is triaging against — enough to tell two companies
     * apart in a cross-tenant list without a second lookup (UC-1-127 step 7).
     *
     * @return array{id: ?string, name: ?string, br_number: ?string, epf_number: ?string, plan: ?string, status: ?string}
     */
    private function companySummary(?Company $company): array
    {
        return [
            'id' => $company?->getKey(),
            'name' => $company?->name,
            'br_number' => $company?->br_number,
            'epf_number' => $company?->epf_number,
            'plan' => $company?->current_billing_plan?->value,
            'status' => $company?->status?->value,
        ];
    }

    /**
     * Id + name + REAL email. The owner-facing resource masks the target's
     * address; the admin surface is the forensic view and does not.
     *
     * @return array{id: ?string, name: ?string, email: ?string}
     */
    private function userSummary(?User $user): array
    {
        return [
            'id' => $user?->getKey(),
            'name' => $user?->name,
            'email' => $user?->email,
        ];
    }
}
