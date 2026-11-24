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
    public function __construct(private readonly DsarRequestVisibility $visibility) {}

    /**
     * @return LengthAwarePaginator<int, DsarRequestRecord>
     */
    public function execute(ListDsarRequestsCommand $command, User $actor, Company $company): LengthAwarePaginator
    {
        return $this->visibility->visibleTo($actor, $company)
            ->with(['subject', 'requestedBy'])
            ->when($command->status, fn ($query, DsarRequestStatus $status) => $query->where('status', $status))
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            // page defaults here, never through Laravel's resolver, which would
            // honour a body-supplied page past the validated query filters.
            ->paginate(perPage: $command->perPage ?? 15, page: $command->page ?? 1);
    }
}
