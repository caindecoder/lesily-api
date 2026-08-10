<?php

namespace App\Companies\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Companies\Enums\OwnershipTransferCaseStatus;
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
    public function execute(ExecuteOwnershipTransferCaseCommand $command, User $admin, AdminRequestContext $context): OwnershipTransferCase
    {
        return DB::transaction(function () use ($command): OwnershipTransferCase {
            $case = OwnershipTransferCase::query()
                ->lockForUpdate()
                ->findOrFail($command->caseId);

            if ($case->status !== OwnershipTransferCaseStatus::Verified) {
                throw new OwnershipTransferCaseNotVerifiedException($case->status);
            }

            throw new RuntimeException('Not implemented');
        });
    }
}
