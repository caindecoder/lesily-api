<?php

namespace App\Companies\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Companies\Enums\OwnershipTransferCaseStatus;
use App\Companies\UseCases\Commands\OpenOwnershipTransferCaseCommand;

/**
 * A platform admin puts a support-assisted ownership handover on the record
 * (UC-1-140; slice-11R spec §4). The case row and its audit row share ONE
 * transaction — an audit failure vetoes the open (the Minimal Guarantee:
 * a case exists only alongside its evidence). This is deliberately stricter
 * than the invoice-review precedent, whose read-side audit write is
 * transaction-free observation.
 */
class OpenOwnershipTransferCaseInteractor
{
    public function execute(OpenOwnershipTransferCaseCommand $command, User $admin, AdminRequestContext $context): OwnershipTransferCase
    {
        return DB::transaction(function () use ($command, $admin, $context): OwnershipTransferCase {
            $case = OwnershipTransferCase::query()->create([
                'company_id' => $command->companyId,
                'expected_owner_user_id' => Company::query()
                    ->whereKey($command->companyId)
                    ->value('owner_id'),
                'status' => OwnershipTransferCaseStatus::Open,
                'new_owner_user_id' => $command->newOwnerUserId,
                'requested_context' => $command->requestedContext,
            ]);

            $this->recordCaseOpened($case, $admin, $context);

            return $case;
        });
    }

    private function recordCaseOpened(OwnershipTransferCase $case, User $admin, AdminRequestContext $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $case->company_id,
            'stored_event_id' => null,
            'event_class' => 'OwnershipTransferCaseOpened',
            'subject_type' => 'ownership_transfer_case',
            'subject_id' => $case->id,
            'subject_label' => null,
            'action' => 'case_opened',
            'description' => 'Platform admin opened an ownership-transfer case.',
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
