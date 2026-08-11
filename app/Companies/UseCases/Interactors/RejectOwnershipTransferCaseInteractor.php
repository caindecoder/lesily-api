<?php

namespace App\Companies\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Companies\Enums\OwnershipTransferCaseStatus;
use App\Companies\Enums\OwnershipTransferCaseOutcome;
use App\Companies\UseCases\Commands\RejectOwnershipTransferCaseCommand;
use App\Companies\Exceptions\OwnershipTransferCaseAlreadyClosedException;

/**
 * A platform admin rejects an ownership-transfer case (UC-1-128; slice-11R
 * spec §5): a plain write that closes the case with its reason on the record
 * and never touches company or membership state.
 */
class RejectOwnershipTransferCaseInteractor
{
    public function execute(RejectOwnershipTransferCaseCommand $command, User $admin, AdminRequestContext $context): OwnershipTransferCase
    {
        return DB::transaction(function () use ($command, $admin, $context): OwnershipTransferCase {
            $case = OwnershipTransferCase::query()
                ->lockForUpdate()
                ->findOrFail($command->caseId);

            if ($case->status === OwnershipTransferCaseStatus::Closed) {
                throw new OwnershipTransferCaseAlreadyClosedException;
            }

            $case->update([
                'status' => OwnershipTransferCaseStatus::Closed,
                'closed_outcome' => OwnershipTransferCaseOutcome::Rejected,
                'rejection_reason' => $command->reason,
                'rejection_reason_code' => $command->reasonCode,
            ]);

            $this->recordCaseRejected($case, $admin, $context);

            return $case;
        });
    }

    private function recordCaseRejected(OwnershipTransferCase $case, User $admin, AdminRequestContext $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $case->company_id,
            'stored_event_id' => null,
            'event_class' => 'OwnershipTransferCaseRejected',
            'subject_type' => 'ownership_transfer_case',
            'subject_id' => $case->id,
            'subject_label' => null,
            'action' => 'case_rejected',
            'description' => 'Platform admin rejected an ownership-transfer case.',
            'actor_user_id' => $admin->id,
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }
}
