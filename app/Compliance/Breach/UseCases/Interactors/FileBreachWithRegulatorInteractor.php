<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\UseCases\Commands\FileBreachWithRegulatorCommand;
use App\Compliance\Breach\Exceptions\RegulatorAddressNotConfiguredException;

class FileBreachWithRegulatorInteractor
{
    public function execute(FileBreachWithRegulatorCommand $command, User $admin, RequestOrigin $context): BreachIncidentRecord
    {
        if (blank(config('platform.regulator_address'))) {
            throw RegulatorAddressNotConfiguredException::forFiling();
        }

        return DB::transaction(function () use ($command): BreachIncidentRecord {
            $breach = BreachIncidentRecord::query()->whereKey($command->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            BreachIncidentCompany::query()
                ->where('breach_incident_id', $breach->getKey())
                ->whereCompany($command->company)
                ->first()
                ?? throw new ModelNotFoundException;

            throw new RuntimeException('Not implemented');
        });
    }
}
