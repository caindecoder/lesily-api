<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\Http\Values\AdminRequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Dsar\Exceptions\DsarExportNotReadyException;
use App\Compliance\Dsar\UseCases\Commands\DownloadDsarExportCommand;

class DownloadDsarExportInteractor
{
    public function execute(DownloadDsarExportCommand $command, User $actor, AdminRequestContext $context): DsarRequestRecord
    {
        $company = Company::query()->findOrFail($command->companyId);

        $request = DsarRequestRecord::query()->whereCompany($company)->whereSubject($actor)->whereKey($command->requestId)->first()
            ?? throw new ModelNotFoundException;

        if ($request->status !== DsarRequestStatus::Fulfilled) {
            throw new DsarExportNotReadyException;
        }

        return $request;
    }
}
