<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use App\Compliance\Breach\Enums\BreachStage;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Breach\Exceptions\BreachIncidentNotRecordedException;
use App\Compliance\Breach\UseCases\Commands\NotifyBreachCompaniesCommand;

class NotifyBreachCompaniesInteractor
{
    public function execute(NotifyBreachCompaniesCommand $command, User $admin, RequestOrigin $context): BreachIncidentRecord
    {
        return DB::transaction(function () use ($command): BreachIncidentRecord {
            $breach = BreachIncidentRecord::query()->whereKey($command->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            if ($breach->stage !== BreachStage::Recorded) {
                throw new BreachIncidentNotRecordedException($breach->stage);
            }

            throw new RuntimeException('Not implemented');
        });
    }
}
