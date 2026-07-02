<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Companies\Memberships\Exceptions\AlreadyMemberException;
use App\Companies\Memberships\UseCases\Commands\InviteUserCommand;

class InviteUserInteractor
{
    public function execute(Company $company, InviteUserCommand $command): void
    {
        $userId = User::query()->where('email', $command->email)->value('id');

        // A soft-deleted (removed) membership is NOT a member — its re-invite is
        // the D5b undelete-in-place path below, not an ALREADY_MEMBER rejection.
        if ($userId !== null && $company->users()->whereKey($userId)->wherePivotNull('deleted_at')->exists()) {
            throw new AlreadyMemberException;
        }

        $removedMembershipId = $userId === null ? null : CompanyUser::onlyTrashed()
            ->where('company_id', $company->getKey())
            ->where('user_id', $userId)
            ->value('id');

        // Fake-It (lap 6): a removed member's re-invite reuses the soft-deleted
        // membership's UUID as the invitation id (D5b — stable FK anchor). The
        // event-sourced UserMembership aggregate + MembershipProjector replace
        // this direct write in the happy-path lap.
        if ($removedMembershipId !== null) {
            DB::table('invitations')->insert([
                'id' => $removedMembershipId,
                'company_id' => $company->getKey(),
                'role_id' => Role::query()->where('code', $command->roleCode)->sole()->getKey(),
                'email' => $command->email,
                'token_hash' => hash('sha256', Str::random(40)),
                'expires_at' => now()->addDays(7),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
