<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\UseCases\Commands\DsarRequestFilters;
use App\Foundation\RolesPermissions\Services\PermissionEvaluator;

class ListDsarRequestsInteractor
{
    public const string VIEW_ALL = 'dsar.view-all';

    public function __construct(private readonly PermissionEvaluator $permissions) {}

    /**
     * @return LengthAwarePaginator<int, DsarRequestRecord>
     */
    public function execute(DsarRequestFilters $filters, User $actor, Company $company): LengthAwarePaginator
    {
        return DsarRequestRecord::query()
            ->with(['subject', 'requestedBy'])
            ->whereCompany($company)
            ->unless($this->permissions->canUserDo($actor, $company, self::VIEW_ALL), fn ($query) => $query->whereVisibleTo($actor))
            ->paginate();
    }
}
