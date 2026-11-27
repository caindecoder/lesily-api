<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use Throwable;
use RuntimeException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Compliance\Breach\Events\BreachDeadlineMissed;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class FlagMissedBreachDeadlinesInteractor
{
    public function execute(CarbonInterface $scannedAt): int
    {
        /** @var array<int, string> $breachIds */
        $breachIds = BreachIncidentRecord::query()->whereDeadlineMissedToFlag($scannedAt)->pluck('id')->all();

        $flagged = 0;

        foreach ($breachIds as $breachId) {
            try {
                if ($this->flag($breachId, $scannedAt)) {
                    $flagged++;
                }
            } catch (Throwable $throwable) {
                report(new RuntimeException("Breach deadline flag failed for incident {$breachId}.", previous: $throwable));
            }
        }

        return $flagged;
    }

    protected function flag(string $breachId, CarbonInterface $scannedAt): bool
    {
        return DB::transaction(function () use ($breachId, $scannedAt): bool {
            BreachIncidentRecord::query()->whereKey($breachId)->lockForUpdate()->firstOrFail();

            $breachIncident = BreachIncident::retrieve($breachId)->flagMissedDeadline($scannedAt);

            if ($breachIncident->getRecordedEvents() === []) {
                return false;
            }

            $breachIncident->persist();
            $this->recordAudit($breachId);

            return true;
        });
    }

    protected function recordAudit(string $breachId): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->flaggedEventIdFor($breachId),
            'event_class' => 'BreachDeadlineMissed',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breachId,
            'subject_label' => null,
            'action' => AuditAction::BreachDeadlineMissed,
            'description' => 'A data breach passed its 72-hour deadline with its companies not yet notified.',
            'actor_user_id' => null,
            'actor_role_code' => null,
            'actor_label' => null,
            'ip_address' => null,
            'user_agent' => null,
            'request_id' => null,
            'metadata' => null,
            'severity' => 'critical',
            'recorded_at' => now(),
        ]);
    }

    protected function flaggedEventIdFor(string $breachId): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachId)
            ->where('event_class', BreachDeadlineMissed::class)
            ->latest('id')
            ->firstOrFail()
            ->getKey();
    }
}
