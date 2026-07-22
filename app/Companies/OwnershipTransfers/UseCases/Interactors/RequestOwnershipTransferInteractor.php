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

        $this->guardTargetIsNotSelf($command);
        $this->guardTargetIsMember($company, $command->targetUserId);

        throw new RuntimeException('Not implemented');
    }

    /**
     * The owner cannot transfer to themselves (api-1-ownership-transfer §7
     * OQ #5 — the target must differ from the current owner). Checked before
     * the membership read: no query needed to refuse it.
     */
    private function guardTargetIsNotSelf(RequestOwnershipTransferCommand $command): void
    {
        if ($command->targetUserId === $command->currentOwnerUserId) {
            throw ValidationException::withMessages([
                'targetUserId' => 'The target user must be different from the current owner.',
            ]);
        }
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
