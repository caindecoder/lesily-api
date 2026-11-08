<?php

namespace Tests\Feature\Compliance\Breach;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\Events\BreachDeadlineMissed;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * The once-only missed-deadline guard at the domain seam (conv-plan §2.14 Rule C):
 * the daily scan selects only recorded, unflagged incidents past their deadline,
 * so the aggregate's own guard is unreachable through it — it protects a racing
 * run, future callers and replay integrity, and is provable only at this seam.
 */
class FlagMissedBreachDeadlineOnceTest extends BreachIncidentsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-05-08 00:00:00');
    }

    #[Test]
    #[DataProvider('provideIncidentsTheAggregateKeeps')]
    public function when_an_incident_cannot_be_flagged_then_flagging_it_directly_records_nothing(string $state): void
    {
        // Given an incident the deadline flag does not apply to
        $breachId = $this->anIncidentThat($state);
        $flagsBefore = $this->flagCountOf($breachId);

        // When its aggregate is asked to flag the missed deadline now
        $incident = BreachIncident::retrieve($breachId)->flagMissedDeadline(CarbonImmutable::now());

        // Then nothing is recorded and the store is unchanged
        $this->assertSame([], $incident->getRecordedEvents());
        $this->assertSame($flagsBefore, $this->flagCountOf($breachId));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideIncidentsTheAggregateKeeps(): array
    {
        return [
            'its companies were notified' => ['its companies were notified'],
            'it is already flagged' => ['it is already flagged'],
            'it is before its deadline' => ['it is before its deadline'],
        ];
    }

    protected function anIncidentThat(string $state): string
    {
        return match ($state) {
            'its companies were notified' => $this->aNotifiedIncident($this->companyA),
            'it is already flagged' => $this->aFlaggedIncident(),
            'it is before its deadline' => $this->aRecordedIncident(['dateDiscovered' => '2026-05-06T00:00:00Z']),
            default => throw new InvalidArgumentException("Unknown state: {$state}"),
        };
    }

    protected function aFlaggedIncident(): string
    {
        $breachId = $this->aRecordedIncident();
        BreachIncident::retrieve($breachId)->flagMissedDeadline(CarbonImmutable::now())->persist();

        return $breachId;
    }

    protected function flagCountOf(string $breachId): int
    {
        return LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachDeadlineMissed::class)->count();
    }
}
