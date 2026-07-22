<?php

namespace App\Companies\OwnershipTransfers\IO\Http\Resources;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

/**
 * The owner-facing transfer shape (UC-1-125/126, snake_case per the built
 * convention): the SM-1-05 state + its lifecycle timestamps + derived guidance
 * (next_action / expected_completion_at / support_channel), the masked target,
 * and the requester. Deliberately EXCLUDES verification artefacts, verifier
 * notes, admin attribution, and raw user ids — those stay on the admin resource
 * (UC-1-127). The target email is always masked (UC-1-125 minimal guarantee).
 *
 * @property-read OwnershipTransferRequest $resource
 */
class OwnershipTransferRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'company_id' => $this->resource->company_id,
            'status' => $this->resource->status,
            'request_channel' => $this->resource->request_channel,
            'reason' => $this->resource->reason,
            'requested_at' => $this->resource->requested_at,
            'verified_at' => $this->resource->verified_at,
            'executed_at' => $this->resource->executed_at,
            'rejected_at' => $this->resource->rejected_at,
            'cancelled_at' => $this->resource->cancelled_at,
            'expired_at' => $this->resource->expired_at,
            'expires_at' => $this->resource->next_state_transition_at,
            'cancellation_reason' => $this->resource->cancellation_reason,
            'expiry_source' => $this->resource->expiry_source,
            'terminating_actor' => $this->terminatingActor(),
            'next_action' => $this->nextAction(),
            'expected_completion_at' => $this->expectedCompletionAt(),
            'support_channel' => $this->supportChannel(),
            'target_user' => $this->userSummary($this->resource->targetUser),
            'requested_by' => ['name' => $this->resource->currentOwner?->name],
        ];
    }

    /**
     * Who ended the transfer — derived from the terminal state, null while open.
     */
    private function terminatingActor(): ?string
    {
        return match ($this->resource->status) {
            OwnershipTransferStatus::Executed, OwnershipTransferStatus::Rejected => 'platform_admin',
            OwnershipTransferStatus::Cancelled => 'owner',
            OwnershipTransferStatus::Expired => 'system_timer',
            OwnershipTransferStatus::Requested, OwnershipTransferStatus::Verified => null,
        };
    }

    /**
     * The owner-facing "what happens next" line for the current state.
     */
    private function nextAction(): string
    {
        return match ($this->resource->status) {
            OwnershipTransferStatus::Requested => 'Awaiting platform admin identity verification',
            OwnershipTransferStatus::Verified => 'Awaiting platform admin to execute the transfer',
            OwnershipTransferStatus::Executed => 'Transfer complete',
            OwnershipTransferStatus::Rejected => 'Transfer request was rejected',
            OwnershipTransferStatus::Cancelled => 'Transfer request was withdrawn',
            OwnershipTransferStatus::Expired => 'Transfer request expired',
        };
    }

    /**
     * The default SLA hint for the queue wait — surfaced ONLY while the request
     * awaits verification (OQ #4). Once verified the owner tracks the execute
     * window via expires_at, and terminal transfers have no pending completion,
     * so both return null (a `requested_at + N`-day date would read as stale).
     */
    private function expectedCompletionAt(): ?CarbonImmutable
    {
        if ($this->resource->status !== OwnershipTransferStatus::Requested) {
            return null;
        }

        return $this->resource->requested_at->addWeekdays(
            (int) config('ownership-transfers.completion_business_days', 5),
        );
    }

    /**
     * The follow-up channel echoed only while the request awaits verification.
     *
     * @return array{email: string, in_app_url: string}|null
     */
    private function supportChannel(): ?array
    {
        if ($this->resource->status !== OwnershipTransferStatus::Requested) {
            return null;
        }

        return [
            'email' => (string) config('ownership-transfers.support_email', 'support@lesily.com'),
            'in_app_url' => '/settings/company/ownership-transfers',
        ];
    }

    /**
     * Name + masked email — the target's full email is never exposed to the
     * tenant (UC-1-125 minimal guarantee).
     *
     * @return array{name: ?string, email_masked: ?string}
     */
    private function userSummary(?User $user): array
    {
        return [
            'name' => $user?->name,
            'email_masked' => $user !== null ? $this->maskEmail($user->email) : null,
        ];
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return $local[0].'***@'.$domain;
    }
}
