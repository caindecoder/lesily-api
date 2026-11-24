<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use App\Foundation\RolesPermissions\Entities\Role;

trait CreatesMemberships
{
    /**
     * $user joins $company under $role and accepts the live policy versions there.
     */
    protected function attachMember(Company $company, User $user, Role $role): void
    {
        $company->users()->attach($user, ['role_id' => $role->getKey()]);
        $this->aConsentRecordedBy($user, $company);
    }

    /**
     * A fresh user who joins $company under the seeded $roleCode and accepts the
     * live policy versions there.
     */
    protected function aCompanyMember(Company $company, string $roleCode = 'employee'): User
    {
        $user = User::factory()->create();
        $this->attachMember($company, $user, $this->seededRole($roleCode));

        return $user;
    }

    /**
     * Soft-delete ($user)'s membership pivot in $company — the removed-member
     * state (UC-1-024) without going through the endpoint's session kill, so a
     * still-valid session can prove the R7 auth-gate filters trashed memberships.
     */
    protected function softDeleteMembership(Company $company, User $user): void
    {
        CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->update(['deleted_at' => now()]);
    }

    /**
     * A member of $company holding the SEEDED $roleCode. Returns the user and
     * the membership pivot id (the aggregate identity, D5b).
     *
     * @return array{0: User, 1: string}
     */
    protected function aMembership(Company $company, string $roleCode): array
    {
        $user = User::factory()->create();
        $this->attachMember($company, $user, $this->seededRole($roleCode));

        return [$user, $this->membershipIdOf($company, $user)];
    }

    /**
     * The membership pivot id for ($company, $user) — the aggregate identity.
     */
    protected function membershipIdOf(Company $company, User $user): string
    {
        return CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->sole()
            ->getKey();
    }

    /**
     * A membership that was removed (UC-1-024 semantics): the company_user pivot
     * exists but is soft-deleted. Returns the pivot id — the stable membership
     * UUID a re-invite must reuse (D5b undelete-in-place).
     */
    protected function aRemovedMembership(Company $company, User $user): string
    {
        $this->attachMember($company, $user, $this->seededRole('employee'));
        $membershipId = $this->membershipIdOf($company, $user);
        $this->softDeleteMembership($company, $user);

        return $membershipId;
    }
}
