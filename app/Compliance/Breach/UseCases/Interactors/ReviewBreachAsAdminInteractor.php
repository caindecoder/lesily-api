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
    public function execute(string $breachId, User $admin, RequestOrigin $context): BreachIncidentRecord
    {
        return DB::transaction(function () use ($breachId, $admin, $context): BreachIncidentRecord {
            $breach = BreachIncidentRecord::query()->with('companies.company')->whereKey($breachId)->first()
                ?? throw new ModelNotFoundException;

            $this->recordAdminRead($breach, $admin, $context);

            return $breach;
        });
    }

    protected function recordAdminRead(BreachIncidentRecord $breach, User $admin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'company_id' => null,
            'stored_event_id' => null,
            'event_class' => 'BreachIncidentViewedByAdmin',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breach->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachAdminViewed,
            'description' => 'Platform admin viewed a data breach.',
            'actor_user_id' => $admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'severity' => 'info',
            'recorded_at' => now(),
        ]);
    }
}
