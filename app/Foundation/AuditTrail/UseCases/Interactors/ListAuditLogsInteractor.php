<?php

namespace App\Foundation\AuditTrail\UseCases\Interactors;

use App\Companies\Entities\Company;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\AuditTrail\Values\AuditLogPage;
use App\Foundation\AuditTrail\UseCases\Queries\AuditLogRows;
use App\Foundation\Billing\Exceptions\FeatureUnavailableException;
use App\Foundation\AuditTrail\UseCases\Commands\ListAuditLogsCommand;

class ListAuditLogsInteractor
{
    public function __construct(protected AuditLogRows $auditLogRows) {}

    public function execute(ListAuditLogsCommand $filters, Company $company): AuditLogPage
    {
        if ($filters->usesSearch() && ! $company->canUseFeature(Feature::AuditLogSearch)) {
            throw FeatureUnavailableException::forFeature(Feature::AuditLogSearch);
        }

        $rows = $this->auditLogRows->of($filters, $company)
            ->paginate(perPage: $filters->perPage ?? 15, page: $filters->page ?? 1);

        return new AuditLogPage($rows, $this->auditLogRows->retentionFrom($company));
    }
}
