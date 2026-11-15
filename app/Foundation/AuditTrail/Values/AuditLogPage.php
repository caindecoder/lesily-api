<?php

namespace App\Foundation\AuditTrail\Values;

use stdClass;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * One page of the merged audit logs and the first day the company's plan keeps.
 */
readonly class AuditLogPage
{
    /**
     * @param  LengthAwarePaginator<int, stdClass>  $rows
     */
    public function __construct(
        public LengthAwarePaginator $rows,
        public CarbonImmutable $retentionFrom,
    ) {}
}
