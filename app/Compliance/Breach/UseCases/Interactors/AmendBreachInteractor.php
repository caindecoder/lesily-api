<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Compliance\Breach\Enums\BreachStage;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Breach\UseCases\Commands\AmendBreachCommand;
use App\Compliance\Breach\Exceptions\BreachAmendmentRefusedException;

class AmendBreachInteractor
{
    public function execute(AmendBreachCommand $command, User $admin, RequestOrigin $context): BreachIncidentRecord
    {
        return DB::transaction(function () use ($command, $admin): BreachIncidentRecord {
            $breach = BreachIncidentRecord::query()->whereKey($command->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $this->guardOpen($breach);

            BreachIncident::retrieve($breach->getKey())->amend($command, $admin)->persist();

            return $breach;
        });
    }

    protected function guardOpen(BreachIncidentRecord $breach): void
    {
        match ($breach->stage) {
            BreachStage::Resolved => throw BreachAmendmentRefusedException::incidentResolved(),
            BreachStage::FalseAlarm => throw BreachAmendmentRefusedException::falseAlarm(),
            BreachStage::Recorded, BreachStage::CompaniesNotified => null,
        };
    }
}
