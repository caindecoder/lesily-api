<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use App\Compliance\Breach\Enums\BreachStage;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Breach\Exceptions\BreachFalseAlarmRefusedException;
use App\Compliance\Breach\UseCases\Commands\MarkBreachFalseAlarmCommand;

class MarkBreachFalseAlarmInteractor
{
    public function execute(MarkBreachFalseAlarmCommand $command, User $admin, RequestOrigin $context): BreachIncidentRecord
    {
        return DB::transaction(function () use ($command, $admin): BreachIncidentRecord {
            $breach = BreachIncidentRecord::query()->whereKey($command->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $this->guardOpen($breach);

            BreachIncident::retrieve($breach->getKey())->markFalseAlarm($command, $admin)->persist();

            throw new RuntimeException('Not implemented');
        });
    }

    protected function guardOpen(BreachIncidentRecord $breach): void
    {
        match ($breach->stage) {
            BreachStage::Resolved => throw BreachFalseAlarmRefusedException::incidentResolved(),
            BreachStage::FalseAlarm => throw BreachFalseAlarmRefusedException::alreadyMarked(),
            BreachStage::Recorded, BreachStage::CompaniesNotified => null,
        };
    }
}
