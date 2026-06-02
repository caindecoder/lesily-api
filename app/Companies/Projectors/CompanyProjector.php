<?php

namespace App\Companies\Projectors;

use App\Companies\Enums\CompanyStatus;
use App\Companies\Events\CompanyCreated;
use App\Companies\Events\CompanyTrialExpired;
use App\Companies\Entities\Company as CompanyReadModel;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Mirrors the Company aggregate's lifecycle events into the `companies` read
 * model (ERD-1-02). A new company opens a 30-day trial with no plan yet — the
 * plan is set later, on CompanySubscribed (CD-1-02).
 */
class CompanyProjector extends Projector
{
    public function onCompanyCreated(CompanyCreated $event): void
    {
        CompanyReadModel::query()->create([
            'id' => $event->aggregateRootUuid(),
            'name' => $event->name,
            'status' => CompanyStatus::Trial,
            'trial_ends_at' => $event->trialEndsAt,
        ]);
    }

    public function onCompanyTrialExpired(CompanyTrialExpired $event): void
    {
        CompanyReadModel::query()->findOrFail($event->aggregateRootUuid())
            ->update(['status' => CompanyStatus::TrialExpired]);
    }
}
