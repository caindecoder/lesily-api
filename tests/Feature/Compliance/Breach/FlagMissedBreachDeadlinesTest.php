<?php

namespace Tests\Feature\Compliance\Breach;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use App\Compliance\Breach\Enums\BreachStage;
use App\Compliance\Breach\Events\BreachDeadlineMissed;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class FlagMissedBreachDeadlinesTest extends BreachIncidentsTestCase
{
    use InteractsWithQueuedReactors;

    protected const string THE_NIGHT_AFTER = '2026-05-08 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::THE_NIGHT_AFTER);
        Queue::fake();
    }

    #[Test]
    public function when_the_date_is_not_a_date_then_the_scan_is_refused_and_nothing_is_flagged(): void
    {
        // Given an incident still recorded past its deadline
        $this->aRecordedIncident();

        // When the daily scan runs with a date that is not a date
        $this->runFlagMissedDeadlines('not-a-date')->expectsOutputToContain('The --date option must be a date, such as 2026-10-31.')->assertFailed();

        // Then nothing is flagged
        $this->assertNothingFlagged();
    }

    #[Test]
    public function when_the_date_is_in_the_future_then_the_scan_is_refused_and_nothing_is_flagged(): void
    {
        // Given an incident still recorded past its deadline
        $this->aRecordedIncident();

        // When the daily scan runs with a date later than now
        $this->runFlagMissedDeadlines('next week')->expectsOutputToContain('The --date option cannot be later than now.')->assertFailed();

        // Then nothing is flagged
        $this->assertNothingFlagged();
    }

    #[Test]
    public function when_no_recorded_incident_is_past_its_deadline_then_nothing_is_flagged(): void
    {
        // Given an incident discovered two days ago, its deadline tomorrow
        $this->aRecordedIncident(['dateDiscovered' => '2026-05-06T00:00:00Z']);

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput('0 missed deadline(s) flagged.')->assertSuccessful();

        // Then nothing is flagged
        $this->assertNothingFlagged();
    }

    #[Test]
    public function when_a_recorded_incident_misses_its_deadline_then_the_flag_is_recorded_and_audited(): void
    {
        // Given an incident discovered 2026-05-04 14:00 UTC, still recorded at the night after its deadline
        $breachId = $this->aRecordedIncident();

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput('1 missed deadline(s) flagged.')->assertSuccessful();

        // Then the incident is flagged at the run's cutoff, still recorded, and the flag audited
        $this->assertFlagged($breachId, CarbonImmutable::parse(self::THE_NIGHT_AFTER));
        $this->assertFlagAudited($breachId);
    }

    protected function runFlagMissedDeadlines(?string $date = null): PendingCommand
    {
        $command = $this->artisan('breaches:flag-missed-deadlines', $date !== null ? ['--date' => $date] : []);
        assert($command instanceof PendingCommand);

        return $command;
    }

    protected function assertNothingFlagged(): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachDeadlineMissed::class)->count());
        $this->assertSame(0, BreachIncidentRecord::query()->whereNotNull('deadline_missed_at')->count());
        $this->assertNoHandlersQueuedFor(BreachDeadlineMissed::class);
    }

    protected function assertFlagged(string $breachId, CarbonImmutable $missedAt): void
    {
        $event = LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachDeadlineMissed::class)->sole();
        $this->assertSame(['breachId' => $breachId, 'missedAt' => $missedAt->toAtomString()], $event->event_properties);

        $incident = BreachIncidentRecord::query()->findOrFail($breachId);
        $this->assertEquals($missedAt, $incident->deadline_missed_at);
        $this->assertSame(BreachStage::Recorded, $incident->stage);
    }

    protected function assertFlagAudited(string $breachId): void
    {
        $storedEventId = LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachDeadlineMissed::class)->sole()->getKey();
        $this->assertIsInt($storedEventId);

        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => null,
            'stored_event_id' => $storedEventId,
            'event_class' => 'BreachDeadlineMissed',
            'subject_type' => 'breach_incident',
            'subject_id' => $breachId,
            'subject_label' => null,
            'action' => 'breach_deadline_missed',
            'description' => 'A data breach passed its 72-hour deadline with its companies not yet notified.',
            'actor_user_id' => null,
            'actor_role_code' => null,
            'actor_label' => null,
            'ip_address' => null,
            'user_agent' => null,
            'request_id' => null,
            'metadata' => null,
            'severity' => 'critical',
            'recorded_at' => self::THE_NIGHT_AFTER,
        ]);
    }
}
