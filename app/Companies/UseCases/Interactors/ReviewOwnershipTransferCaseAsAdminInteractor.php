<?php

namespace App\Companies\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;

/**
 * The admin case detail read (UC-1-127): one cross-tenant case plus ONE
 * audit row, written in the same transaction as the read — an audit failure
 * vetoes the read (no read without evidence, slice-11R spec §4). The find
 * precedes the audit write: no evidence row for a read that didn't happen
 * (ReviewInvoiceAsAdminInteractor ordering).
 */
class ReviewOwnershipTransferCaseAsAdminInteractor
{
    public function execute(string $caseId, User $platformAdmin, RequestOrigin $context): OwnershipTransferCase
    {
        return DB::transaction(function () use ($caseId, $platformAdmin, $context): OwnershipTransferCase {
            $case = OwnershipTransferCase::query()->findOrFail($caseId);

            $this->recordCaseRead($case, $platformAdmin, $context);

            return $case;
        });
    }

    protected function recordCaseRead(OwnershipTransferCase $case, User $platformAdmin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $case->company_id,
            'stored_event_id' => null,
            'event_class' => 'OwnershipTransferCaseViewed',
            'subject_type' => AuditSubjectType::OwnershipTransferCase,
            'subject_id' => $case->getKey(),
            'subject_label' => null,
            'action' => AuditAction::CaseViewed,
            'description' => 'Platform admin viewed an ownership-transfer case.',
            'actor_user_id' => $platformAdmin->getKey(),
            'actor_role_code' => AuditEntry::STAFF_ROLE_CODE,
            'actor_label' => $platformAdmin->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }
}
