<?php

namespace App\Companies\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\UseCases\Audit\CompanyAudit;
use App\Companies\UseCases\Audit\CompanyChange;
use App\Foundation\AuditTrail\Values\FieldChanges;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Companies\Entities\CompanyDataProtectionOfficer;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\UseCases\Commands\RemoveDataProtectionOfficerCommand;

/**
 * The owner or an admin removes the company's data protection officer (UC-1-146
 * alt 3c); with none recorded nothing is recorded (alt 3d).
 */
class RemoveDataProtectionOfficerInteractor
{
    public function __construct(protected readonly CompanyAudit $audit) {}

    public function execute(RemoveDataProtectionOfficerCommand $command, RequestOrigin $origin): void
    {
        $companyId = $command->company->getKey();
        /** @var string $removedBy users are UUID-keyed (HasUuids) */
        $removedBy = $command->removedBy->getKey();

        DB::transaction(function () use ($command, $companyId, $removedBy, $origin): void {
            $recorded = CompanyDataProtectionOfficer::query()->where('company_id', $companyId)->lockForUpdate()->first();

            if ($recorded === null) {
                return;
            }

            CompanyAggregate::retrieve($companyId)->removeDataProtectionOfficer($removedBy)->persist();

            $changes = FieldChanges::between(
                ['name' => $recorded->name, 'email' => $recorded->email, 'phone' => $recorded->phone],
                ['name' => null, 'email' => null, 'phone' => null],
                ['name', 'email', 'phone'],
            );
            $this->audit->changed(CompanyChange::dataProtectionOfficerRemovedOf($command->company, $changes), $command->removedBy, $origin);
        });
    }
}
