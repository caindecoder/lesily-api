<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use App\Companies\Entities\Company;
use Illuminate\Validation\ValidationException;
use App\Companies\OwnershipTransfers\UseCases\Commands\RequestOwnershipTransferCommand;

/**
 * Request an ownership transfer (UC-1-028, SM-1-05 T1): validate the command,
 * guard the target's eligibility, and record OwnershipTransferRequested for
 * the platform-support verification queue.
 */
class RequestOwnershipTransferInteractor
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(Company $company, User $actor, array $input): never
    {
        $command = RequestOwnershipTransferCommand::validateAndCreate([
            ...$input,
            'companyId' => $company->getKey(),
            'currentOwnerUserId' => $actor->getKey(),
        ]);

        $this->guardTargetIsMember($company, $command->targetUserId);

        throw new RuntimeException('Not implemented');
    }

    /**
     * The target must hold a live (non-soft-deleted) membership in the company
     * — a body-reference validation per the F4 ruling (422 VALIDATION_FAILED,
     * InviteUserInteractor pivot-read precedent).
     */
    private function guardTargetIsMember(Company $company, string $targetUserId): void
    {
        $isMember = $company->users()
            ->whereKey($targetUserId)
            ->wherePivotNull('deleted_at')
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages([
                'targetUserId' => 'The selected target user is not a member of this company.',
            ]);
        }
    }
}
