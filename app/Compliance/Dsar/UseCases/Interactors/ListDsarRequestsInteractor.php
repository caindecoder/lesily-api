<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\UseCases\Queries\DsarRequestVisibility;
use App\Compliance\Dsar\UseCases\Commands\ListDsarRequestsCommand;

class ListDsarRequestsInteractor
{
    public function __construct(private readonly DsarRequestVisibility $dsarRequestVisibility) {}

    /**
     * @return LengthAwarePaginator<int, DsarRequestRecord>
     */
    public function execute(ListDsarRequestsCommand $listDsarRequestsCommand, User $actor, Company $company): LengthAwarePaginator
    {
        return $this->dsarRequestVisibility->visibleTo($actor, $company)
            ->with(['subject', 'requestedBy'])
            ->when($listDsarRequestsCommand->status, fn ($query, DsarRequestStatus $dsarRequestStatus) => $query->where('status', $dsarRequestStatus))
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            // page defaults here, never through Laravel's resolver, which would
            // honour a body-supplied page past the validated query filters.
            ->paginate(perPage: $listDsarRequestsCommand->perPage ?? 15, page: $listDsarRequestsCommand->page ?? 1);
    }
}
