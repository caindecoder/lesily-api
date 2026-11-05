<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ReviewBreachQueueInteractor
{
    /**
     * @return LengthAwarePaginator<int, BreachIncidentRecord>
     */
    public function execute(User $admin, RequestOrigin $context): LengthAwarePaginator
    {
        return DB::transaction(fn (): LengthAwarePaginator => BreachIncidentRecord::query()->paginate());
    }
}
