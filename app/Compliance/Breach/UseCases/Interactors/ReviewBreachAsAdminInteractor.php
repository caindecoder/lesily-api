<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ReviewBreachAsAdminInteractor
{
    public function execute(string $breachId, User $admin, RequestOrigin $context): BreachIncidentRecord
    {
        return DB::transaction(function () use ($breachId): BreachIncidentRecord {
            return BreachIncidentRecord::query()->with('companies.company')->whereKey($breachId)->first()
                ?? throw new ModelNotFoundException;
        });
    }
}
