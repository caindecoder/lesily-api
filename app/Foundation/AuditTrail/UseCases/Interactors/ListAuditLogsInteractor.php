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

    public function execute(ListAuditLogsCommand $listAuditLogsCommand, Company $company): AuditLogPage
    {
        if ($listAuditLogsCommand->usesSearch() && ! $company->canUseFeature(Feature::AuditLogSearch)) {
            throw FeatureUnavailableException::forFeature(Feature::AuditLogSearch);
        }

        $lengthAwarePaginator = $this->auditLogRows->of($listAuditLogsCommand, $company)
            ->paginate(perPage: $listAuditLogsCommand->perPage ?? 15, page: $listAuditLogsCommand->page ?? 1);

        return new AuditLogPage($lengthAwarePaginator, $this->auditLogRows->retentionFrom($company));
    }
}
