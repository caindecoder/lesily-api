<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Compliance\Breach\Enums\BreachStage;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\Events\BreachCompanyAdded;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\UseCases\Commands\AddBreachCompanyCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\Exceptions\LateBreachCompanyRefusedException;
use App\Compliance\Breach\UseCases\Renderers\BreachCompanyPartRenderer;

class AddBreachCompanyInteractor
{
    public function __construct(protected readonly BreachCompanyPartRenderer $breachCompanyPartRenderer) {}

    public function execute(AddBreachCompanyCommand $addBreachCompanyCommand, User $platformAdmin, RequestOrigin $requestOrigin): BreachIncidentRecord
    {
        return DB::transaction(function () use ($addBreachCompanyCommand, $platformAdmin, $requestOrigin): BreachIncidentRecord {
            $breachIncident = BreachIncidentRecord::query()->whereKey($addBreachCompanyCommand->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $this->guardOpen($breachIncident);

            BreachIncident::retrieve($breachIncident->getKey())
                ->addLateCompany($addBreachCompanyCommand, $platformAdmin, $this->partFor($breachIncident, $addBreachCompanyCommand))
                ->persist();

            $this->recordAudit($breachIncident, $platformAdmin, $requestOrigin);

            return BreachIncidentRecord::query()->with(['companies.company', 'amendments'])->whereKey($breachIncident->getKey())->firstOrFail();
        });
    }

    protected function guardOpen(BreachIncidentRecord $breachIncident): void
    {
        match ($breachIncident->stage) {
            BreachStage::Resolved => throw LateBreachCompanyRefusedException::incidentResolved(),
            BreachStage::FalseAlarm => throw LateBreachCompanyRefusedException::falseAlarm(),
            BreachStage::Recorded, BreachStage::CompaniesNotified => null,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function partFor(BreachIncidentRecord $breachIncident, AddBreachCompanyCommand $addBreachCompanyCommand): array
    {
        $figures = $addBreachCompanyCommand->figures();

        return $this->breachCompanyPartRenderer->render($breachIncident, new BreachIncidentCompany([
            'data_types' => $figures['dataTypes'],
            'estimated_count' => $figures['estimatedCount'],
        ]));
    }

    protected function recordAudit(BreachIncidentRecord $breachIncident, User $platformAdmin, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->addedEventIdFor($breachIncident),
            'event_class' => 'BreachCompanyAdded',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breachIncident->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachCompanyAdded,
            'description' => 'Platform admin added a company found after the breach notices and told it.',
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

    protected function addedEventIdFor(BreachIncidentRecord $breachIncident): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachIncident->getKey())
            ->where('event_class', BreachCompanyAdded::class)
            ->latest('id')
            ->firstOrFail()
            ->getKey();
    }
}
