<?php

namespace App\Companies\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\CompanyUser;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Companies\Enums\OwnershipTransferCaseStatus;
use App\Companies\Exceptions\TargetNotMemberException;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\Events\CompanyOwnershipVerificationRecorded;
use App\Companies\Exceptions\OwnershipTransferCaseNotOpenException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\UseCases\Commands\VerifyOwnershipTransferCaseCommand;

/**
 * A platform admin records how the identity behind a support-assisted
 * handover was proven (UC-1-113; slice-11R spec §2/§4). The event, the case
 * stamp and the audit row land together or not at all — the audit row
 * references the stored event, never the notes, and the notes reach only the
 * case row.
 */
class VerifyOwnershipTransferCaseInteractor
{
    public function execute(VerifyOwnershipTransferCaseCommand $command, User $admin, AdminRequestContext $context): OwnershipTransferCase
    {
        return DB::transaction(function () use ($command, $admin, $context): OwnershipTransferCase {
            $case = OwnershipTransferCase::query()->lockForUpdate()->findOrFail($command->caseId);

            $this->guardCaseIsOpen($case);
            $this->guardTargetIsLiveMember($case, $command->newOwnerUserId);

            CompanyAggregate::retrieve($case->company_id)
                ->recordOwnershipVerification($command, $admin->id)
                ->persist();

            $verificationEventId = $this->verificationEventIdFor($case);

            $case->update([
                'status' => OwnershipTransferCaseStatus::Verified,
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'new_owner_user_id' => $command->newOwnerUserId,
                'verification_notes' => $command->notes,
                'verification_artefacts' => $command->artefactMetadata(),
                'verification_event_id' => $verificationEventId,
            ]);

            $this->recordCaseVerified($case, $admin, $context);

            return $case;
        });
    }

    private function guardCaseIsOpen(OwnershipTransferCase $case): void
    {
        if ($case->status !== OwnershipTransferCaseStatus::Open) {
            throw new OwnershipTransferCaseNotOpenException($case->status);
        }
    }

    /**
     * The incoming owner must hold a LIVE membership of the case's company
     * (UC-1-113 error flow 3c) — decided against the case row, never the
     * payload, so a membership elsewhere can never satisfy it.
     */
    private function guardTargetIsLiveMember(OwnershipTransferCase $case, string $newOwnerUserId): void
    {
        $membership = CompanyUser::query()
            ->where('company_id', $case->company_id)
            ->where('user_id', $newOwnerUserId)
            ->first();

        if ($membership === null) {
            throw new TargetNotMemberException;
        }
    }

    /**
     * The id of the verification event this call just persisted — read back
     * inside the same transaction by IDENTITY (aggregate + event class + the
     * case named in the payload), never by insert order, and fail-loud via
     * sole(): a missing or ambiguous record rolls the whole verification
     * back rather than stamping an evidence chain with the wrong id.
     */
    private function verificationEventIdFor(OwnershipTransferCase $case): int
    {
        return (int) LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->where('event_class', CompanyOwnershipVerificationRecorded::class)
            ->where('event_properties->caseId', $case->getKey())
            ->sole()
            ->id;
    }

    private function recordCaseVerified(OwnershipTransferCase $case, User $admin, AdminRequestContext $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $case->company_id,
            'stored_event_id' => $case->verification_event_id,
            'event_class' => 'CompanyOwnershipVerificationRecorded',
            'subject_type' => 'ownership_transfer_case',
            'subject_id' => $case->id,
            'subject_label' => null,
            'action' => 'case_verified',
            'description' => 'Platform admin recorded identity verification on an ownership-transfer case.',
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
