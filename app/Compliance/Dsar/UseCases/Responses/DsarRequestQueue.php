<?php

namespace App\Compliance\Dsar\UseCases\Responses;

use Illuminate\Pagination\LengthAwarePaginator;
use App\Compliance\Dsar\Entities\DsarRequestRecord;

/**
 * The use case's response model (E07): one page of the queue and the counts
 * of the in-progress requests among everything the filters selected.
 */
class DsarRequestQueue
{
    /**
     * @param  LengthAwarePaginator<int, DsarRequestRecord>  $page
     */
    public function __construct(
        public readonly LengthAwarePaginator $page,
        public readonly int $open,
        public readonly int $overdue,
        public readonly int $dueInSevenDays,
    ) {}
}
