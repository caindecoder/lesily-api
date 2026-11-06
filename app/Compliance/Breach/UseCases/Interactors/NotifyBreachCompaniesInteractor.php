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
    public function __construct(protected BreachCompanyPartRenderer $renderer) {}

    public function execute(NotifyBreachCompaniesCommand $command, User $admin, RequestOrigin $context): BreachIncidentRecord
    {
        return DB::transaction(function () use ($command, $admin, $context): BreachIncidentRecord {
            $breach = BreachIncidentRecord::query()->with('companies')->whereKey($command->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            if ($breach->stage !== BreachStage::Recorded) {
                throw new BreachIncidentNotRecordedException($breach->stage);
            }

            $parts = $breach->companies->mapWithKeys(fn (BreachIncidentCompany $company): array => [
                $company->company_id => $this->renderer->render($breach, $company),
            ])->all();

            BreachIncident::retrieve($breach->getKey())->notifyCompanies($admin, $parts)->persist();

            $this->recordAudit($breach, $admin, $context);

            return BreachIncidentRecord::query()->with('companies.company')->whereKey($breach->getKey())->firstOrFail();
        });
    }

    protected function recordAudit(BreachIncidentRecord $breach, User $admin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->notifiedEventIdFor($breach),
            'event_class' => 'BreachCompaniesNotified',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breach->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachCompaniesNotified,
            'description' => 'Platform admin notified the companies affected by a data breach.',
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

    protected function notifiedEventIdFor(BreachIncidentRecord $breach): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breach->getKey())
            ->where('event_class', BreachCompaniesNotified::class)
            ->sole()
            ->getKey();
    }
}
