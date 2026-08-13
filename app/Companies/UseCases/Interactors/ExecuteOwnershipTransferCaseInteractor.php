<?php

namespace App\Companies\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Database\QueryException;
use Illuminate\Database\DeadlockException;
use App\Foundation\Database\LockContention;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Companies\Enums\OwnershipTransferCaseStatus;
use App\Companies\Enums\OwnershipTransferCaseOutcome;
use App\Companies\Events\CompanyOwnershipTransferred;
use App\Companies\Exceptions\OwnershipTransferContentionException;
use App\Companies\UseCases\Commands\TransferCompanyOwnershipCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\Exceptions\OwnershipTransferCaseNotVerifiedException;
use App\Companies\UseCases\Commands\ExecuteOwnershipTransferCaseCommand;

/**
 * A platform admin executes a VERIFIED ownership-transfer case (UC-1-093;
 * slice-11R spec §4/§5): case-state guard first, then the routine path's own
 * locked re-validation via the composed transfer interactor, then the case
 * close and its audit row — all inside ONE transaction, so an audit failure
 * vetoes the swap, the event and the close together (the Minimal Guarantee).
 */
class ExecuteOwnershipTransferCaseInteractor
{
    public function __construct(private TransferCompanyOwnershipInteractor $transferInteractor) {}

    public function execute(ExecuteOwnershipTransferCaseCommand $command, User $admin, AdminRequestContext $context): OwnershipTransferCase
    {
        try {
            return $this->executeLocked($command, $admin, $context);
        } catch (DeadlockException $exception) {
            throw new OwnershipTransferContentionException($exception);
        } catch (QueryException $exception) {
            throw LockContention::caused($exception)
                ? new OwnershipTransferContentionException($exception)
                : $exception;
        }
    }

    private function executeLocked(ExecuteOwnershipTransferCaseCommand $command, User $admin, AdminRequestContext $context): OwnershipTransferCase
    {
        return DB::transaction(function () use ($command, $admin, $context): OwnershipTransferCase {
            $case = OwnershipTransferCase::query()
                ->lockForUpdate()
                ->findOrFail($command->caseId);

            if ($case->status !== OwnershipTransferCaseStatus::Verified) {
                throw new OwnershipTransferCaseNotVerifiedException($case->status);
            }

            $company = Company::query()->findOrFail($case->company_id);

            $this->transferInteractor->execute($company, TransferCompanyOwnershipCommand::validateAndCreate([
                'newOwnerUserId' => $case->new_owner_user_id,
                'transferredBy' => $admin->id,
                'source' => TransferCompanyOwnershipCommand::SOURCE_ADMIN,
                'caseId' => $case->getKey(),
            ]), $case->expected_owner_user_id);

            $case->update([
                'status' => OwnershipTransferCaseStatus::Closed,
                'closed_outcome' => OwnershipTransferCaseOutcome::Executed,
                'executed_at' => now(),
                'executed_by' => $admin->id,
                'transfer_event_id' => $this->transferEventIdFor($case),
            ]);

            $this->recordCaseExecuted($case, $admin, $context);

            return $case;
        });
    }

    /**
     * The id of the transfer event this call just persisted — read back
     * inside the same transaction by IDENTITY (aggregate + event class + the
     * case named in the payload), never by insert order: routine transfers
     * carry caseId NULL and a sibling case cites its OWN event, so sole()
     * fail-louds on anything but exactly this case's record.
     */
    private function transferEventIdFor(OwnershipTransferCase $case): int
    {
        return (int) LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->where('event_properties->caseId', $case->getKey())
            ->sole()
            ->id;
    }

    private function recordCaseExecuted(OwnershipTransferCase $case, User $admin, AdminRequestContext $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $case->company_id,
            'stored_event_id' => $case->transfer_event_id,
            'event_class' => 'CompanyOwnershipTransferred',
            'subject_type' => 'ownership_transfer_case',
            'subject_id' => $case->id,
            'subject_label' => null,
            'action' => 'case_executed',
            'description' => 'Platform admin executed a verified ownership-transfer case.',
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
