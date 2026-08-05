<?php

namespace App\Companies\Projectors;

use App\Companies\Enums\CompanyStatus;
use App\Companies\Entities\CompanyUser;
use App\Companies\Events\CompanyCreated;
use App\Companies\Events\CompanyTrialExpired;
use App\Companies\Events\CompanyDetailsUpdated;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Companies\Events\CompanyOwnershipTransferred;
use App\Companies\Entities\Company as CompanyReadModel;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Mirrors the Company aggregate's lifecycle events into the `companies` read
 * model (ERD-1-02). A new company opens a 30-day trial with no plan yet — the
 * plan is set later, on CompanySubscribed (CD-1-02).
 */
class CompanyProjector extends Projector
{
    public function onCompanyCreated(CompanyCreated $event): void
    {
        $company = CompanyReadModel::query()->create([
            'id' => $event->aggregateRootUuid(),
            'name' => $event->name,
            'br_number' => $event->brNumber,
            'epf_number' => $event->epfNumber,
            'country' => $event->country,
            'owner_id' => $event->ownerUserId,
            'status' => CompanyStatus::Trial,
            'trial_ends_at' => $event->trialEndsAt,
        ]);

        // The owner's membership pivot lands with the company genesis: the FK
        // chain (users → companies → company_user) makes CompanyCreated — which
        // carries ownerUserId — the earliest event where both ends exist (slice
        // C1/C4 placement confirmed at the #04 pre-flight). The 'owner' role comes
        // from the seeded UC-1-136 catalog (deploy precondition).
        $company->users()->attach($event->ownerUserId, [
            'role_id' => Role::query()->where('code', 'owner')->sole()->getKey(),
        ]);
    }

    public function onCompanyTrialExpired(CompanyTrialExpired $event): void
    {
        CompanyReadModel::query()->findOrFail($event->aggregateRootUuid())
            ->update(['status' => CompanyStatus::TrialExpired]);
    }

    public function onCompanyDetailsUpdated(CompanyDetailsUpdated $event): void
    {
        CompanyReadModel::query()->whereKey($event->aggregateRootUuid())
            ->update(['name' => $event->name]);
    }

    /**
     * The dual-representation swap in ONE arm (spec §4): the owner column and
     * both company_user role rows move together, so they can never diverge.
     * Roles resolved by seeded code (genesis parity); withTrashed for replay
     * fidelity (MembershipProjector parity).
     */
    public function onCompanyOwnershipTransferred(CompanyOwnershipTransferred $event): void
    {
        $companyId = $event->aggregateRootUuid();

        CompanyReadModel::query()->whereKey($companyId)
            ->update(['owner_id' => $event->newOwnerUserId]);

        CompanyUser::withTrashed()
            ->where('company_id', $companyId)
            ->where('user_id', $event->previousOwnerUserId)
            ->update(['role_id' => Role::query()->where('code', 'admin')->sole()->getKey()]);

        CompanyUser::withTrashed()
            ->where('company_id', $companyId)
            ->where('user_id', $event->newOwnerUserId)
            ->update(['role_id' => Role::query()->where('code', 'owner')->sole()->getKey()]);
    }
}
