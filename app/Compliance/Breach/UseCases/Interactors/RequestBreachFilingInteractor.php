<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\UseCases\Commands\RequestBreachFilingCommand;

class RequestBreachFilingInteractor
{
    public function execute(RequestBreachFilingCommand $command, User $owner, RequestOrigin $context): BreachIncidentCompany
    {
        return DB::transaction(function () use ($command): BreachIncidentCompany {
            $breach = BreachIncidentRecord::query()->whereKey($command->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            BreachIncidentCompany::query()
                ->where('breach_incident_id', $breach->getKey())
                ->whereCompany($command->company)
                ->whereNotified()
                ->first()
                ?? throw new ModelNotFoundException;

            throw new RuntimeException('Not implemented');
        });
    }
}
