<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use Illuminate\Validation\ValidationException;
use App\Companies\OwnershipTransfers\Exceptions\EmergencyContactRequiredException;
use App\Companies\OwnershipTransfers\Exceptions\OpenOwnershipTransferExistsException;
use App\Companies\OwnershipTransfers\UseCases\Commands\RequestOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;

/**
 * Request an ownership transfer (UC-1-028, SM-1-05 T1): validate the command,
 * guard the target's eligibility, and record OwnershipTransferRequested for
 * the platform-support verification queue.
 */
class RequestOwnershipTransferInteractor
{
    public function __construct(
        private readonly OwnershipTransferRequestRepositoryInterface $transfers,
    ) {}

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
        $membership = $this->guardTargetIsMember($company, $command->targetUserId);
        $this->guardTargetHasEmergencyContact($membership);
        $this->guardNoOpenTransfer($company);

        throw new RuntimeException('Not implemented');
    }

    /**
     * Tier-1 of the one-open-per-company invariant (api-1-ownership-transfer
     * §2): the sync projection read at submit time. The aggregate's genesis
     * re-fire guard is the Tier-2 backstop on its own stream.
     */
    private function guardNoOpenTransfer(Company $company): void
    {
        if ($this->transfers->findOpenByCompany($company->getKey()) !== null) {
            throw new OpenOwnershipTransferExistsException;
        }
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
     * InviteUserInteractor pivot-read precedent). Returns the pivot row so the
     * emergency-contact guard reads from the same single query (design D-c).
     */
    private function guardTargetIsMember(Company $company, string $targetUserId): CompanyUser
    {
        $membership = CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $targetUserId)
            ->whereNull('deleted_at')
            ->first();

        if ($membership === null) {
            throw ValidationException::withMessages([
                'targetUserId' => 'The selected target user is not a member of this company.',
            ]);
        }

        return $membership;
    }

    /**
     * §S10b makes the emergency contact mandatory for owners — the incoming
     * owner must already have one on their membership before the request can
     * enter the verification queue. The columns are written together, so the
     * name is the presence marker.
     */
    private function guardTargetHasEmergencyContact(CompanyUser $membership): void
    {
        if ($membership->emergency_contact_name === null) {
            throw new EmergencyContactRequiredException;
        }
    }
}
