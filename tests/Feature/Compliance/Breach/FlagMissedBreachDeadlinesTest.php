<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use App\Compliance\Breach\Enums\BreachStage;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\Events\BreachDeadlineMissed;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Foundation\Notifications\Enums\NotificationType;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Compliance\Breach\UseCases\Commands\AmendBreachCommand;
use App\Foundation\Notifications\Notifications\SystemNotification;
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
    public function when_a_flagged_incident_is_scanned_again_then_it_is_not_flagged_or_alerted_twice(): void
    {
        // Given an incident flagged by last night's run, and the clock a day on
        $breachId = $this->aRecordedIncident();
        $this->runFlagMissedDeadlines()->assertSuccessful();
        $this->travel(1)->day();

        // When the daily scan runs again
        $this->runFlagMissedDeadlines()->expectsOutput('0 missed deadline(s) flagged.')->assertSuccessful();

        // Then the incident keeps its one flag, its one audit row and its one alert
        $this->assertFlagged($breachId, CarbonImmutable::parse(self::THE_NIGHT_AFTER));
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertSame(1, $this->queuedHandlerCountFor(BreachDeadlineMissed::class));
    }

    #[Test]
    public function when_another_run_flagged_the_incident_first_then_it_is_neither_counted_nor_audited_again(): void
    {
        // Given a missed deadline already flagged on its stream, its row not yet showing it
        $breachId = $this->aRecordedIncident();
        BreachIncident::retrieve($breachId)->flagMissedDeadline(CarbonImmutable::now())->persist();
        BreachIncidentRecord::query()->whereKey($breachId)->update(['deadline_missed_at' => null]);

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput('0 missed deadline(s) flagged.')->assertSuccessful();

        // Then no second flag is recorded and none is audited
        $this->assertSame(1, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachDeadlineMissed::class)->count());
        $this->assertDatabaseCount('audit_trail', 0);
    }

    #[Test]
    #[DataProvider('provideStagesPastRecorded')]
    public function when_an_incident_past_the_recorded_stage_misses_its_deadline_then_it_is_not_flagged(string $stage): void
    {
        // Given an incident past the recorded stage, its deadline gone
        $this->anIncidentInStage($stage);

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput('0 missed deadline(s) flagged.')->assertSuccessful();

        // Then nothing is flagged
        $this->assertNothingFlagged();
    }

    #[Test]
    #[DataProvider('provideFailingPositions')]
    public function when_one_flag_fails_then_it_is_reported_and_the_others_are_still_flagged(int $failingPosition): void
    {
        // Given three incidents past their deadlines, and an audit trail that refuses the row of one of them
        Exceptions::fake();
        $breachIds = [
            $this->aRecordedIncident(),
            $this->aRecordedIncident(['dateDiscovered' => '2026-05-04T16:00:00Z']),
            $this->aRecordedIncident(['dateDiscovered' => '2026-05-04T18:00:00Z']),
        ];
        $failing = $breachIds[$failingPosition];
        $cause = new RuntimeException('induced audit failure');
        AuditEntry::creating(fn (AuditEntry $entry) => $entry->subject_id === $failing ? throw $cause : null);

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput('2 missed deadline(s) flagged.')->assertSuccessful();

        // Then the failing incident is left unflagged and reported with its cause; the others are flagged
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $failing)->where('event_class', BreachDeadlineMissed::class)->count());
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported->getMessage() === "Breach deadline flag failed for incident {$failing}."
            && $reported->getPrevious() === $cause);
        $this->assertSame(2, BreachIncidentRecord::query()->whereNotNull('deadline_missed_at')->count());
    }

    /**
     * @param  Closure(): list<string>  $allowlisted
     */
    #[Test]
    #[DataProvider('provideAdminGaps')]
    public function when_no_platform_admin_can_be_alerted_then_the_gap_is_reported_and_the_flag_kept(Closure $allowlisted): void
    {
        // Given an incident still recorded past its deadline, and no platform admin who can be alerted
        Exceptions::fake();
        $breachId = $this->aRecordedIncident();
        $this->allowlist($allowlisted());

        // When the daily scan runs and the alerts leave the queue
        $this->runFlagMissedDeadlines()->assertSuccessful();
        $this->runQueuedHandlersFor(BreachDeadlineMissed::class);

        // Then the flag is kept, the gap is reported, and nobody is sent anything
        $this->assertFlagged($breachId, CarbonImmutable::parse(self::THE_NIGHT_AFTER));
        Exceptions::assertReported(fn (RuntimeException $gap): bool => $gap->getMessage() === "No platform admin to alert about the missed deadline of breach incident {$breachId}.");
        Notification::assertNothingSent();
    }

    #[Test]
    public function when_the_scan_selects_then_only_a_recorded_unflagged_incident_past_its_deadline_is_picked(): void
    {
        // Given one incident due, one not yet due, one already flagged and one whose companies were notified
        $due = $this->anIncident()->createOne()->getKey();
        $this->anIncident()->dueAt('2026-05-09 00:00:00')->create();
        $this->anIncident()->deadlineMissed()->create();
        $this->anIncident()->companiesNotified()->create();

        // When the scan selects at the run's cutoff
        $picked = BreachIncidentRecord::query()->whereDeadlineMissedToFlag(now())->pluck('id')->all();

        // Then only the due one is picked
        $this->assertSame([$due], $picked);
    }

    #[Test]
    #[DataProvider('provideDeadlineEdges')]
    public function when_the_scan_runs_at_the_deadline_edge_then_only_a_missed_deadline_is_flagged(int $secondsPastTheDeadline, int $flagged): void
    {
        // Given an incident whose deadline is 2026-05-07 14:00 UTC, and the clock at the deadline plus the offset
        $this->aRecordedIncident();
        $this->travelTo(CarbonImmutable::parse('2026-05-07 14:00:00')->addSeconds($secondsPastTheDeadline));

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput("{$flagged} missed deadline(s) flagged.")->assertSuccessful();

        // Then the flag exists only once the deadline is reached
        $this->assertSame($flagged, BreachIncidentRecord::query()->whereNotNull('deadline_missed_at')->count());
    }

    #[Test]
    public function when_a_missed_night_is_replayed_then_the_flag_is_stamped_with_its_date(): void
    {
        // Given an incident still recorded past its deadline, and the clock the morning after a missed night
        $breachId = $this->aRecordedIncident();
        $this->travelTo('2026-05-09 09:00:00');

        // When the scan is replayed for the missed night
        $this->runFlagMissedDeadlines('2026-05-08')->assertSuccessful();

        // Then the flag carries the replayed night, not the clock
        $this->assertFlagged($breachId, CarbonImmutable::parse('2026-05-08 00:00:00'));
    }

    #[Test]
    public function when_the_replayed_date_carries_an_offset_then_the_cutoff_is_read_as_its_utc_instant(): void
    {
        // Given an incident due at 2026-05-08 03:00 UTC, and the clock that morning
        $breachId = $this->aRecordedIncident(['dateDiscovered' => '2026-05-05T03:00:00Z']);
        $this->travelTo('2026-05-08 09:00:00');

        // When the scan is replayed for midnight at UTC-5 — 05:00 UTC, past the deadline
        $this->runFlagMissedDeadlines('2026-05-08T00:00:00-05:00')->expectsOutput('1 missed deadline(s) flagged.')->assertSuccessful();

        // Then the incident is flagged, stamped at that instant in UTC
        $this->assertFlagged($breachId, CarbonImmutable::parse('2026-05-08 05:00:00'));
    }

    #[Test]
    public function when_the_admin_list_is_padded_then_the_listed_admin_is_still_alerted(): void
    {
        // Given an incident still recorded past its deadline, and the admin listed with spaces and a trailing comma
        $this->aRecordedIncident();
        $this->allowlist([" {$this->admin->getKey()} ", '']);

        // When the daily scan runs and the alerts leave the queue
        $this->runFlagMissedDeadlines()->assertSuccessful();
        $this->runQueuedHandlersFor(BreachDeadlineMissed::class);

        // Then the listed admin is alerted once
        Notification::assertSentToTimes($this->admin, SystemNotification::class, 1);
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

    #[Test]
    public function when_a_flag_is_recorded_then_each_platform_admin_is_alerted_once_naming_its_companies(): void
    {
        // Given an incident affecting Zenith Retail and Acme Payroll, named in that order, two allowlisted
        // Lesily admins, and both companies' owners
        $zenith = Company::factory()->named('Zenith Retail')->create();
        $acme = Company::factory()->named('Acme Payroll')->create();
        $breachId = $this->aRecordedIncident(['companies' => [
            ['companyId' => $zenith->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 12],
            ['companyId' => $acme->getKey(), 'dataTypes' => ['payroll'], 'estimatedCount' => 30],
        ]]);
        $this->allowlist([$this->admin->getKey(), User::factory()->create()->getKey()]);
        $this->anOwnerOf($zenith);
        $this->anOwnerOf($acme);

        // When the daily scan runs and the alerts leave the queue
        $this->runFlagMissedDeadlines()->assertSuccessful();
        $this->runQueuedHandlersFor(BreachDeadlineMissed::class);

        // Then each admin is alerted once, naming the companies by name, the incident and its deadline;
        // no owner is told
        $this->assertAdminsAlertedOnce($breachId, 'Acme Payroll, Zenith Retail');
    }

    #[Test]
    public function when_a_corrected_discovery_date_puts_the_deadline_behind_then_the_scan_flags_it(): void
    {
        // Given an incident discovered 2026-05-06 12:00 UTC (due 2026-05-09 12:00), whose discovery was then
        // corrected to 2026-05-04 20:00 UTC - due 2026-05-07 20:00, before tonight's run
        $breachId = $this->aRecordedIncident(['dateDiscovered' => '2026-05-06T12:00:00Z']);
        $this->correctTheDiscovery($breachId, '2026-05-04T20:00:00Z');

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput('1 missed deadline(s) flagged.')->assertSuccessful();

        // Then the incident is flagged against its corrected deadline
        $this->assertFlagged($breachId, CarbonImmutable::parse(self::THE_NIGHT_AFTER));
    }

    #[Test]
    public function when_a_cleared_flag_s_new_deadline_passes_then_the_scan_flags_it_again(): void
    {
        // Given an incident flagged tonight, whose discovery was then corrected to 2026-05-05 12:00 UTC -
        // due 2026-05-08 12:00, so the flag was cleared - and the next night has come
        $breachId = $this->aRecordedIncident();
        $this->runFlagMissedDeadlines();
        $this->correctTheDiscovery($breachId, '2026-05-05T12:00:00Z');
        $this->travelTo('2026-05-09 00:00:00');

        // When the daily scan runs again
        $this->runFlagMissedDeadlines()->expectsOutput('1 missed deadline(s) flagged.')->assertSuccessful();

        // Then the incident is flagged a second time, against the new night
        $this->assertSame(2, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachDeadlineMissed::class)->count());
        $this->assertEquals(CarbonImmutable::parse('2026-05-09 00:00:00'), BreachIncidentRecord::query()->findOrFail($breachId)->deadline_missed_at);
    }

    #[Test]
    public function when_several_incidents_miss_their_deadline_then_one_run_flags_them_all(): void
    {
        // Given two incidents still recorded past their deadlines
        $first = $this->aRecordedIncident();
        $second = $this->aRecordedIncident(['dateDiscovered' => '2026-05-04T20:00:00Z']);

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput('2 missed deadline(s) flagged.')->assertSuccessful();

        // Then both are flagged and each alert is queued
        $this->assertFlagged($first, CarbonImmutable::parse(self::THE_NIGHT_AFTER));
        $this->assertFlagged($second, CarbonImmutable::parse(self::THE_NIGHT_AFTER));
        $this->assertSame(2, $this->queuedHandlerCountFor(BreachDeadlineMissed::class));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideStagesPastRecorded(): array
    {
        return [
            'companies notified' => ['companies notified'],
            'resolved' => ['resolved'],
            'false alarm' => ['false alarm'],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideFailingPositions(): array
    {
        return [
            'the first incident fails' => [0],
            'the last incident fails' => [2],
        ];
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function provideDeadlineEdges(): array
    {
        return [
            'one second before the deadline' => [-1, 0],
            'at the deadline' => [0, 1],
        ];
    }

    /**
     * @return array<string, array{Closure(): list<string>}>
     */
    public static function provideAdminGaps(): array
    {
        return [
            'no admin listed' => [fn (): array => []],
            'the listed id is no user' => [fn (): array => [(string) Str::uuid()]],
        ];
    }

    protected function runFlagMissedDeadlines(?string $date = null): PendingCommand
    {
        $command = $this->artisan('breaches:flag-missed-deadlines', $date !== null ? ['--date' => $date] : []);
        assert($command instanceof PendingCommand);

        return $command;
    }

    protected function anIncidentInStage(string $stage): string
    {
        return match ($stage) {
            'companies notified' => $this->aNotifiedIncident($this->companyA),
            'resolved' => $this->anIncident()->resolved()->createOne()->getKey(),
            'false alarm' => $this->anIncident()->falseAlarm()->createOne()->getKey(),
            default => throw new InvalidArgumentException("Unknown stage: {$stage}"),
        };
    }

    protected function assertNothingFlagged(): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachDeadlineMissed::class)->count());
        $this->assertSame(0, BreachIncidentRecord::query()->whereNotNull('deadline_missed_at')->count());
        $this->assertNoHandlersQueuedFor(BreachDeadlineMissed::class);
    }

    protected function assertAdminsAlertedOnce(string $breachId, string $companies): void
    {
        $admins = User::query()->whereKey(config('platform.admin_user_ids'))->get();
        $this->assertCount(2, $admins);
        foreach ($admins as $admin) {
            Notification::assertSentToTimes($admin, SystemNotification::class, 1);
            Notification::assertSentTo($admin, SystemNotification::class, fn (SystemNotification $alert): bool => $alert->type === NotificationType::BreachDeadlineMissed
                && $alert->companyName === $companies
                && $alert->companyId === null
                && $alert->details === ['incident' => $breachId, 'deadline' => '7 May 2026, 14:00 UTC']);
        }
        Notification::assertCount(2);
    }

    protected function correctTheDiscovery(string $breachId, string $discovered): void
    {
        BreachIncident::retrieve($breachId)
            ->amend(AmendBreachCommand::from(['breachId' => $breachId, 'reason' => 'An earlier log entry was found.', 'dateDiscovered' => $discovered]), $this->admin)
            ->persist();
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
