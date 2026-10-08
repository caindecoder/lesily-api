<?php

namespace App\Compliance\Dsar\UseCases\Queries;

use App\Models\User;
use App\Companies\Entities\CompanyUser;
use App\Foundation\RolesPermissions\Entities\Role;

class MemberRoleCode
{
    /**
     * The code of $member's role in the company — the actor role an audit
     * row records.
     */
    public function of(User $member, string $companyId): ?string
    {
        return Role::query()
            ->whereKey(CompanyUser::query()->where('company_id', $companyId)->where('user_id', $member->getKey())->value('role_id'))
            ->value('code');
    }
}
