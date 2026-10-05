<?php

namespace App\Compliance\Dsar\UseCases\Queries;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Builder;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\RolesPermissions\Services\PermissionEvaluator;

class DsarRequestVisibility
{
    public const string VIEW_ALL = 'dsar.view-all';

    public function __construct(private readonly PermissionEvaluator $permissions) {}

    /**
     * The requests in $company that $actor may see: every one with
     * dsar.view-all, otherwise those about them or made by them.
     *
     * @return Builder<DsarRequestRecord>
     */
    public function visibleTo(User $actor, Company $company): Builder
    {
        return DsarRequestRecord::query()
            ->whereCompany($company)
            ->unless($this->permissions->canUserDo($actor, $company, self::VIEW_ALL), fn ($query) => $query->whereVisibleTo($actor));
    }
}
