<?php

namespace Tests\Feature\Compliance\Dsar;

use Closure;
use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use App\Companies\Entities\Company;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Compliance\Dsar\Events\DsarSlaBreached;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class FlagMissedDsarDeadlinesTest extends DsarTestCase
{
    protected Company $company;

    protected User $member;

    /** @var list<User> */
    protected array $admins;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->member = $this->aCompanyMember($this->company);
        $this->admins = [User::factory()->create(), User::factory()->create()];
        $this->allowlist(array_map(fn (User $admin): string => $admin->getKey(), $this->admins));
        Notification::fake();
    }

    #[Test]
    public function when_no_open_request_is_past_its_deadline_then_nothing_is_flagged(): void
    {
        // Given a submitted request whose deadline is still ahead
        $request = $this->aSubmittedRequest($this->company, $this->member);

        // When the daily scan runs with no --date (defaults to now)
        $this->runFlagMissedDeadlines()->expectsOutput('0 missed deadline(s) flagged.')->assertSuccessful();

        // Then the request is untouched
        $this->assertNotFlagged($request);
    }

    #[Test]
    public function when_a_flagged_request_is_scanned_again_then_it_is_not_flagged_or_alerted_twice(): void
    {
        // Given a missed deadline the daily scan already flagged yesterday
        $request = $this->aMissedDeadlineRequest();
        $this->runFlagMissedDeadlines()->assertSuccessful();
        $flaggedAt = now();
        $this->travel(1)->day();

        // When the daily scan runs again today
        $this->runFlagMissedDeadlines()->expectsOutput('0 missed deadline(s) flagged.')->assertSuccessful();

        // Then no second breach is recorded — so no second alert — and its time is unchanged
        $this->assertBreachRecorded($request, $flaggedAt);
    }

    // A concurrent run (a --date replay beside the nightly one) flagged it
    // between this run's selection and its own flag: the stream holds the
    // breach while the read model this run selected from does not yet.
    #[Test]
    public function when_another_run_flagged_the_request_first_then_it_is_neither_counted_nor_audited_again(): void
    {
        // Given a missed deadline already flagged on its stream, its row not yet showing it
        $request = $this->aMissedDeadlineRequest();
        $this->flagDirectly($request);
        DsarRequestRecord::query()->whereKey($request->getKey())->update(['sla_breached_at' => null]);

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput('0 missed deadline(s) flagged.')->assertSuccessful();

        // Then no second breach is recorded and none is audited
        $this->assertSame(1, LesilyStoredEvent::query()->where('aggregate_uuid', $request->getKey())->where('event_class', DsarSlaBreached::class)->count());
        $this->assertDatabaseMissing('audit_trail', ['subject_id' => $request->getKey(), 'action' => 'dsar_deadline_missed']);
    }

    #[Test]
    #[DataProvider('provideClosedRequests')]
    public function when_a_closed_request_is_past_its_deadline_then_it_is_not_flagged(string $closedBy, DsarRequestStatus $status): void
    {
        // Given a request closed before its deadline, the clock now a day past it
        $request = $this->aClosedRequest($this->company, $this->member, $closedBy);
        $this->travelTo($request->sla_deadline->addDay());

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput('0 missed deadline(s) flagged.')->assertSuccessful();

        // Then it is not flagged and stays closed as it was
        $this->assertNotFlagged($request);
        $this->assertStillClosedAs($request, $status);
    }

    #[Test]
    public function when_the_date_is_not_a_date_then_the_scan_is_refused_and_nothing_is_flagged(): void
    {
        // Given a request past its deadline
        $request = $this->aMissedDeadlineRequest();

        // When the scan is run with a --date that is not a date
        $this->runFlagMissedDeadlines('not-a-date')->expectsOutputToContain('The --date option must be a date, such as 2026-10-31.')->assertFailed();

        // Then nothing is flagged
        $this->assertNotFlagged($request);
    }

    #[Test]
    public function when_the_date_is_in_the_future_then_the_scan_is_refused_and_nothing_is_flagged(): void
    {
        // Given a request past its deadline
        $request = $this->aMissedDeadlineRequest();

        // When the scan is run with a --date later than now
        $this->runFlagMissedDeadlines(now()->addDay()->toDateString())->expectsOutputToContain('The --date option cannot be later than now.')->assertFailed();

        // Then nothing is flagged
        $this->assertNotFlagged($request);
    }

    #[Test]
    #[DataProvider('provideFailingPositions')]
    public function when_one_flag_fails_then_it_is_reported_and_the_others_are_still_flagged(int $failingPosition): void
    {
        // Given two missed deadlines, the one at the given position failing to flag
        $requests = [$this->aMissedDeadlineRequest(), $this->aMissedDeadlineRequest($this->aCompanyMember($this->company))];
        $failing = $requests[$failingPosition];
        $other = $requests[1 - $failingPosition];
        $cause = $this->failTheAuditOf($failing);

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput('1 missed deadline(s) flagged.')->assertSuccessful();

        // Then the failure is reported, the failed one is left for the next run, the other is flagged
        $this->assertFlagFailureReported($failing, $cause);
        $this->assertNotFlagged($failing);
        $this->assertBreachRecorded($other, now());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideFailingPositions(): array
    {
        return [
            'the first one fails' => [0],
            'the last one fails' => [1],
        ];
    }

    /**
     * @param  Closure(): array<int, string>  $allowlist
     */
    #[Test]
    #[DataProvider('provideAdminGaps')]
    public function when_no_platform_admin_can_be_alerted_then_the_gap_is_reported_and_the_breach_kept(Closure $allowlist): void
    {
        // Given an allowlist that names no existing user, and a missed deadline the scan flagged
        Exceptions::fake();
        $this->allowlist($allowlist());
        $request = $this->aMissedDeadlineRequest();
        $this->runFlagMissedDeadlines()->assertSuccessful();

        // When the queued alert runs
        $this->deliverTheNoticesOf(DsarSlaBreached::class);

        // Then the gap is reported, no one is sent anything, and the breach stays recorded
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported->getMessage() === "No platform admin to alert about DSAR request {$request->getKey()}.");
        Notification::assertNothingSent();
        $this->assertBreachRecorded($request, now());
    }

    /**
     * @return array<string, array{Closure(): array<int, string>}>
     */
    public static function provideAdminGaps(): array
    {
        return [
            'no admin listed' => [fn (): array => ['']],
            'no listed id is a user' => [fn (): array => [Str::uuid()->toString()]],
        ];
    }

    // The aggregate's own no-op arms (SM-1-06): the scan never selects these
    // requests, so they are asked to flag outright.
    #[Test]
    #[DataProvider('provideRequestsThatCannotBeFlagged')]
    public function when_a_request_cannot_be_flagged_then_flagging_it_directly_records_nothing(string $state, int $kept): void
    {
        // Given a request in a state that cannot be flagged
        $request = $this->aRequestInState($state);
        $breachedAt = $request->fresh()?->sla_breached_at;

        // When its aggregate is asked to flag the deadline directly
        $this->flagDirectly($request);

        // Then no new breach is recorded and its breach time is unchanged
        $this->assertSame($kept, LesilyStoredEvent::query()->where('aggregate_uuid', $request->getKey())->where('event_class', DsarSlaBreached::class)->count());
        $this->assertEquals($breachedAt, $request->fresh()?->sla_breached_at);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function provideRequestsThatCannotBeFlagged(): array
    {
        return [
            'not yet due' => ['not yet due', 0],
            'fulfilled' => ['fulfilled', 0],
            'rejected' => ['rejected', 0],
            'withdrawn' => ['withdrawn', 0],
            'already flagged' => ['already flagged', 1],
        ];
    }

    // The aggregate refuses every request the scan should skip, so each
    // selection filter is proven here directly (N73).
    #[Test]
    #[DataProvider('provideScanStates')]
    public function when_the_scan_selects_then_only_an_open_unflagged_request_past_its_deadline_is_picked(string $state, bool $picked): void
    {
        // Given a request in the state
        $request = $this->aRequestInState($state);

        // When the scan's selection is read for now
        $selected = DsarRequestRecord::query()->whereBreachDueToFlag(now())->pluck('id')->all();

        // Then the request is picked only when it is open, due and not yet flagged
        $this->assertSame($picked ? [$request->getKey()] : [], $selected);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideScanStates(): array
    {
        return [
            'not yet due' => ['not yet due', false],
            'fulfilled' => ['fulfilled', false],
            'rejected' => ['rejected', false],
            'withdrawn' => ['withdrawn', false],
            'already flagged' => ['already flagged', false],
            'due, not flagged' => ['due, not flagged', true],
        ];
    }

    #[Test]
    #[DataProvider('provideDeadlineEdges')]
    public function when_the_scan_runs_at_the_deadline_edge_then_only_a_missed_deadline_is_flagged(int $offsetSeconds, bool $flagged): void
    {
        // Given a submitted request, the clock at its deadline moved by the offset
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->travelTo($request->sla_deadline->addSeconds($offsetSeconds));

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->assertSuccessful();

        // Then it is flagged only once its deadline has been reached
        $flagged ? $this->assertBreachRecorded($request, now()) : $this->assertNotFlagged($request);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function provideDeadlineEdges(): array
    {
        return [
            'one second before the deadline' => [-1, false],
            'exactly at the deadline' => [0, true],
        ];
    }

    #[Test]
    public function when_a_missed_night_is_replayed_then_the_breach_is_stamped_with_its_date(): void
    {
        // Given a request whose deadline passed before a night the scan missed
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->travelTo('2026-11-02 12:00:00');

        // When that night is replayed
        $this->runFlagMissedDeadlines('2026-11-01')->expectsOutput('1 missed deadline(s) flagged.')->assertSuccessful();

        // Then the breach is stamped with the replayed date, not with now
        $this->assertBreachRecorded($request, CarbonImmutable::parse('2026-11-01 00:00:00'));
    }

    #[Test]
    public function when_the_admin_list_is_padded_then_the_listed_admin_is_still_alerted(): void
    {
        // Given an allowlist whose one entry is padded and followed by a blank segment
        $admin = $this->admins[0];
        $this->allowlist([" {$admin->getKey()} ", '']);
        $this->aMissedDeadlineRequest();
        $this->runFlagMissedDeadlines()->assertSuccessful();

        // When the queued alert runs
        $this->deliverTheNoticesOf(DsarSlaBreached::class);

        // Then that admin is alerted, and no one else
        Notification::assertSentToTimes($admin, SystemNotification::class, 1);
        Notification::assertCount(1);
    }

    #[Test]
    public function when_an_open_request_misses_its_deadline_then_the_breach_is_recorded_and_audited(): void
    {
        // Given a request still in progress a day past its 30-day deadline
        $request = $this->aMissedDeadlineRequest();

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput('1 missed deadline(s) flagged.')->assertSuccessful();

        // Then the breach is recorded on the still-open request and audited
        $this->assertBreachRecorded($request, now());
        $this->assertBreachAudited($request, $this->member);
    }

    #[Test]
    public function when_a_breach_is_recorded_then_each_platform_admin_is_alerted_once(): void
    {
        // Given a request still in progress past its deadline
        $request = $this->aMissedDeadlineRequest();

        // When the daily scan flags it and the queued alert runs
        $this->runFlagMissedDeadlines()->assertSuccessful();
        $this->deliverTheNoticesOf(DsarSlaBreached::class);

        // Then each platform admin is alerted once about the request, at platform level
        $this->assertAdminsAlertedOnce($request);
    }

    #[Test]
    public function when_several_requests_miss_their_deadline_then_one_run_flags_them_all(): void
    {
        // Given two requests still in progress past their deadlines
        $first = $this->aMissedDeadlineRequest();
        $second = $this->aMissedDeadlineRequest($this->aCompanyMember($this->company));

        // When the daily scan runs
        $this->runFlagMissedDeadlines()->expectsOutput('2 missed deadline(s) flagged.')->assertSuccessful();

        // Then both breaches are recorded
        $this->assertBreachRecorded($first, now());
        $this->assertBreachRecorded($second, now());
    }

    /**
     * $subject's request in this company — $this->member's by default — with
     * the clock a day past its 30-day deadline.
     */
    protected function aMissedDeadlineRequest(?User $subject = null): DsarRequestRecord
    {
        $request = $this->aSubmittedRequest($this->company, $subject ?? $this->member);
        $this->travelTo($request->sla_deadline->addDay());

        return $request;
    }

    /**
     * $this->member's request in $state, the clock past its deadline —
     * except a request not yet due; an already-flagged one a second after
     * the run that flagged it.
     */
    protected function aRequestInState(string $state): DsarRequestRecord
    {
        $request = match ($state) {
            'not yet due' => $this->aSubmittedRequest($this->company, $this->member),
            'fulfilled', 'rejected', 'withdrawn' => $this->aClosedRequest($this->company, $this->member, $state),
            'due, not flagged', 'already flagged' => $this->aMissedDeadlineRequest(),
            default => throw new InvalidArgumentException("Unknown state: {$state}"),
        };

        if ($state === 'already flagged') {
            $this->runFlagMissedDeadlines()->assertSuccessful();
            $this->travel(1)->second();
        } elseif (in_array($state, ['fulfilled', 'rejected', 'withdrawn'], true)) {
            $this->travelTo($request->sla_deadline->addDay());
        }

        return $request;
    }

    /**
     * Asks $request's aggregate to flag its deadline now, outside the scan.
     */
    protected function flagDirectly(DsarRequestRecord $request): void
    {
        DsarRequest::retrieve($request->getKey())->flagSlaBreach(CarbonImmutable::now())->persist();
    }

    /**
     * The platform admin allowlist, as config/platform.php hands it over.
     *
     * @param  array<int, string>  $ids
     */
    protected function allowlist(array $ids): void
    {
        config(['platform.admin_user_ids' => $ids]);
    }

    /**
     * Each platform admin got one missed-deadline alert naming $request's
     * company, the request and its deadline, filed under no company (N74,
     * N75) — and no one else was sent anything.
     */
    protected function assertAdminsAlertedOnce(DsarRequestRecord $request): void
    {
        $this->assertCount(2, $this->admins);
        foreach ($this->admins as $admin) {
            Notification::assertSentToTimes($admin, SystemNotification::class, 1);
            Notification::assertSentTo($admin, SystemNotification::class, fn (SystemNotification $notice): bool => $notice->type === NotificationType::DsarDeadlineMissed
                && $notice->companyName === $this->company->name
                && $notice->companyId === null
                && $notice->details === ['request' => $request->getKey(), 'deadline' => '31 Oct 2026, 09:00 UTC']);
        }
        Notification::assertCount(count($this->admins));
    }

    /**
     * The scan reported $request's failed flag by its id, keeping $cause.
     */
    protected function assertFlagFailureReported(DsarRequestRecord $request, RuntimeException $cause): void
    {
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported->getMessage() === "DSAR deadline flag failed for request {$request->getKey()}."
            && $reported->getPrevious() === $cause);
    }

    protected function runFlagMissedDeadlines(?string $date = null): PendingCommand
    {
        $command = $this->artisan('dsar:flag-missed-deadlines', $date !== null ? ['--date' => $date] : []);
        assert($command instanceof PendingCommand);

        return $command;
    }

    /**
     * $request's deadline was never flagged: no DsarSlaBreached on its
     * stream and no breach on its row.
     */
    protected function assertNotFlagged(DsarRequestRecord $request): void
    {
        $this->assertNull($request->fresh()?->getAttribute('sla_breached_at'));
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $request->getKey())->where('event_class', DsarSlaBreached::class)->count());
    }
}
