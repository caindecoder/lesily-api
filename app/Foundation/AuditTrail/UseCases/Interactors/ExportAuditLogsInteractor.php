<?php

namespace App\Foundation\AuditTrail\UseCases\Interactors;

use stdClass;
use App\Companies\Entities\Company;
use Illuminate\Support\LazyCollection;
use App\Foundation\AuditTrail\UseCases\Queries\AuditLogRows;
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogExportFilters;

class ExportAuditLogsInteractor
{
    public function __construct(protected AuditLogRows $auditLogRows) {}

    /**
     * @return LazyCollection<int, stdClass>
     */
    public function execute(AuditLogExportFilters $filters, Company $company): LazyCollection
    {
        return $this->auditLogRows->of($filters, $company)->lazy();
    }
}
