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
use App\Compliance\Breach\Events\BreachDeadlineMissed;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Foundation\Notifications\Enums\NotificationType;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
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
        $due = $this->anIncident()->create()->getKey();
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
            'resolved' => $this->anIncident()->resolved()->create()->getKey(),
            'false alarm' => $this->anIncident()->falseAlarm()->create()->getKey(),
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
