<?php

namespace App\Companies\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\UseCases\Commands\ResetCompanySettingCommand;

/**
 * Reset one company setting to its country's default (UC-1-058).
 */
class ResetCompanySettingInteractor
{
    public function execute(ResetCompanySettingCommand $command): void
    {
        /** @var string $resetBy users are UUID-keyed (HasUuids) */
        $resetBy = $command->resetBy->getKey();

        DB::transaction(fn () => CompanyAggregate::retrieve($command->company->getKey())
            ->resetSetting($command->setting, $resetBy)
            ->persist());
    }
}
