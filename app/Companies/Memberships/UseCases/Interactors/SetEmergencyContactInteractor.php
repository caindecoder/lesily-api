<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\Entities\CompanyUser;
use Illuminate\Database\Eloquent\Builder;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Companies\Memberships\UseCases\Audit\MembershipAudit;
use App\Companies\Memberships\UseCases\Commands\UpdateEmergencyContactCommand;

/**
 * The owner replaces their emergency contact for one company (UC-1-029).
 */
class SetEmergencyContactInteractor
{
    public function __construct(protected readonly MembershipAudit $audit) {}

    public function execute(UpdateEmergencyContactCommand $command, RequestOrigin $origin): CompanyUser
    {
        DB::transaction(function () use ($command, $origin): void {
            $membership = $this->membershipOf($command)->lockForUpdate()->sole();
            $changes = $command->changesFrom($membership);

            UserAggregate::retrieve($command->setBy->getKey())
                ->setEmergencyContact($command->company->getKey(), $command)
                ->persist();

            $this->audit->emergencyContactSet($membership, $changes, $origin);
        });

        return $this->membershipOf($command)->sole();
    }

    /** @return Builder<CompanyUser> */
    protected function membershipOf(UpdateEmergencyContactCommand $command): Builder
    {
        return CompanyUser::query()
            ->where('company_id', $command->company->getKey())
            ->where('user_id', $command->setBy->getKey());
    }
}
