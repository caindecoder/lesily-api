<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Compliance\Breach\Enums\BreachStage;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\Events\BreachCompaniesNotified;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\UseCases\Renderers\BreachCompanyPartRenderer;
use App\Compliance\Breach\Exceptions\BreachIncidentNotRecordedException;
use App\Compliance\Breach\UseCases\Commands\NotifyBreachCompaniesCommand;

class NotifyBreachCompaniesInteractor
{
    public function __construct(protected BreachCompanyPartRenderer $breachCompanyPartRenderer) {}

    public function execute(NotifyBreachCompaniesCommand $notifyBreachCompaniesCommand, User $platformAdmin, RequestOrigin $requestOrigin): BreachIncidentRecord
    {
        return DB::transaction(function () use ($notifyBreachCompaniesCommand, $platformAdmin, $requestOrigin): BreachIncidentRecord {
            $breachIncident = BreachIncidentRecord::query()->with('companies')->whereKey($notifyBreachCompaniesCommand->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            if ($breachIncident->stage !== BreachStage::Recorded) {
                throw new BreachIncidentNotRecordedException($breachIncident->stage);
            }

            $parts = $breachIncident->companies->mapWithKeys(fn (BreachIncidentCompany $breachIncidentCompany): array => [
                $breachIncidentCompany->company_id => $this->breachCompanyPartRenderer->render($breachIncident, $breachIncidentCompany),
            ])->all();

            BreachIncident::retrieve($breachIncident->getKey())->notifyCompanies($platformAdmin, $parts)->persist();

            $this->recordAudit($breachIncident, $platformAdmin, $requestOrigin);

            return BreachIncidentRecord::query()->with(['companies.company', 'amendments'])->whereKey($breachIncident->getKey())->firstOrFail();
        });
    }

    protected function recordAudit(BreachIncidentRecord $breachIncident, User $platformAdmin, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->notifiedEventIdFor($breachIncident),
            'event_class' => 'BreachCompaniesNotified',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breachIncident->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachCompaniesNotified,
            'description' => 'Platform admin notified the companies affected by a data breach.',
            'actor_user_id' => $platformAdmin->getKey(),
            'actor_role_code' => AuditEntry::STAFF_ROLE_CODE,
            'actor_label' => $platformAdmin->name,
            'ip_address' => $requestOrigin->ip,
            'user_agent' => $requestOrigin->boundedUserAgent(),
            'request_id' => $requestOrigin->boundedRequestId(),
            'metadata' => null,
            'severity' => 'critical',
            'recorded_at' => now(),
        ]);
    }

    protected function notifiedEventIdFor(BreachIncidentRecord $breachIncident): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachIncident->getKey())
            ->where('event_class', BreachCompaniesNotified::class)
            ->sole()
            ->getKey();
    }
}
