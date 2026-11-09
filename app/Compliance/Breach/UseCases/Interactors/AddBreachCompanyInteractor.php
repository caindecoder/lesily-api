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
    public function __construct(protected readonly BreachCompanyPartRenderer $renderer) {}

    public function execute(AddBreachCompanyCommand $command, User $admin, RequestOrigin $context): BreachIncidentRecord
    {
        return DB::transaction(function () use ($command, $admin, $context): BreachIncidentRecord {
            $breach = BreachIncidentRecord::query()->whereKey($command->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $this->guardOpen($breach);

            BreachIncident::retrieve($breach->getKey())
                ->addLateCompany($command, $admin, $this->partFor($breach, $command))
                ->persist();

            $this->recordAudit($breach, $admin, $context);

            return BreachIncidentRecord::query()->with(['companies.company', 'amendments'])->whereKey($breach->getKey())->firstOrFail();
        });
    }

    protected function guardOpen(BreachIncidentRecord $breach): void
    {
        match ($breach->stage) {
            BreachStage::Resolved => throw LateBreachCompanyRefusedException::incidentResolved(),
            BreachStage::FalseAlarm => throw LateBreachCompanyRefusedException::falseAlarm(),
            BreachStage::Recorded, BreachStage::CompaniesNotified => null,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function partFor(BreachIncidentRecord $breach, AddBreachCompanyCommand $command): array
    {
        $figures = $command->figures();

        return $this->renderer->render($breach, new BreachIncidentCompany([
            'data_types' => $figures['dataTypes'],
            'estimated_count' => $figures['estimatedCount'],
        ]));
    }

    protected function recordAudit(BreachIncidentRecord $breach, User $admin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->addedEventIdFor($breach),
            'event_class' => 'BreachCompanyAdded',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breach->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachCompanyAdded,
            'description' => 'Platform admin added a company found after the breach notices and told it.',
            'actor_user_id' => $admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'severity' => 'critical',
            'recorded_at' => now(),
        ]);
    }

    protected function addedEventIdFor(BreachIncidentRecord $breach): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breach->getKey())
            ->where('event_class', BreachCompanyAdded::class)
            ->latest('id')
            ->firstOrFail()
            ->getKey();
    }
}
