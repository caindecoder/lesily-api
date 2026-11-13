<?php

namespace App\Companies\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\Entities\CompanySettings;
use App\Companies\Aggregates\SettingsBaseline;
use App\Companies\UseCases\Audit\CompanyAudit;
use App\Companies\UseCases\Audit\CompanyChange;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\UseCases\Commands\ResetCompanySettingCommand;

/**
 * Reset one company setting to its country's default (UC-1-058).
 */
class ResetCompanySettingInteractor
{
    public function __construct(protected readonly CompanyAudit $audit) {}

    public function execute(ResetCompanySettingCommand $command, RequestOrigin $origin): void
    {
        /** @var string $resetBy users are UUID-keyed (HasUuids) */
        $resetBy = $command->resetBy->getKey();

        DB::transaction(function () use ($command, $resetBy, $origin): void {
            $settings = CompanySettings::query()
                ->where('company_id', $command->company->getKey())
                ->lockForUpdate()
                ->sole();
            $baseline = SettingsBaseline::of($settings);

            CompanyAggregate::retrieve($command->company->getKey())
                ->resetSetting($command->setting, $baseline, $resetBy)
                ->persist();

            if ($baseline->hasOwnValue($command->setting)) {
                $this->audit->changed(CompanyChange::resetOf($command->company, $settings, $command->setting), $command->resetBy, $origin);
            }
        });
    }
}
