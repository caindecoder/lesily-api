<?php

namespace App\Companies\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\UseCases\Audit\CompanyAudit;
use App\Companies\UseCases\Audit\CompanyChange;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Companies\Entities\CompanyDataProtectionOfficer;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\UseCases\Commands\SetDataProtectionOfficerCommand;

/**
 * The owner or an admin records or replaces the company's data protection officer
 * (UC-1-146); the same values again record nothing.
 */
class SetDataProtectionOfficerInteractor
{
    public function __construct(protected readonly CompanyAudit $audit) {}

    public function execute(SetDataProtectionOfficerCommand $command, RequestOrigin $origin): CompanyDataProtectionOfficer
    {
        $companyId = $command->company->getKey();
        /** @var string $setBy users are UUID-keyed (HasUuids) */
        $setBy = $command->setBy->getKey();

        DB::transaction(function () use ($command, $companyId, $setBy, $origin): void {
            $recorded = CompanyDataProtectionOfficer::query()->where('company_id', $companyId)->lockForUpdate()->first();
            $changes = $command->changesFrom($recorded);

            if ($changes->isEmpty()) {
                return;
            }

            CompanyAggregate::retrieve($companyId)->setDataProtectionOfficer($command, $setBy)->persist();

            $this->audit->changed(CompanyChange::dataProtectionOfficerSetOf($command->company, $changes), $command->setBy, $origin);
        });

        return CompanyDataProtectionOfficer::query()->where('company_id', $companyId)->sole();
    }
}
