<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ReviewBreachAsAdminInteractor
{
    /**
     * The read and its audit row share one transaction — no read without its evidence.
     */
    public function execute(string $breachId, User $platformAdmin, RequestOrigin $requestOrigin): BreachIncidentRecord
    {
        return DB::transaction(function () use ($breachId, $platformAdmin, $requestOrigin): BreachIncidentRecord {
            $breachIncident = BreachIncidentRecord::query()->with(['companies.company', 'amendments'])->whereKey($breachId)->first()
                ?? throw new ModelNotFoundException;

            $this->recordAdminRead($breachIncident, $platformAdmin, $requestOrigin);

            return $breachIncident;
        });
    }

    protected function recordAdminRead(BreachIncidentRecord $breachIncident, User $platformAdmin, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'event_class' => 'BreachIncidentViewedByAdmin',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breachIncident->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachAdminViewed,
            'description' => 'Platform admin viewed a data breach.',
            'actor_user_id' => $platformAdmin->getKey(),
            'actor_role_code' => AuditEntry::STAFF_ROLE_CODE,
            'actor_label' => $platformAdmin->name,
            'ip_address' => $requestOrigin->ip,
            'user_agent' => $requestOrigin->boundedUserAgent(),
            'request_id' => $requestOrigin->boundedRequestId(),
            'metadata' => null,
            'severity' => 'info',
            'recorded_at' => now(),
        ]);
    }
}
