<?php

namespace Tests\Feature\Compliance\Dsar;

use Closure;
use App\Models\User;
use RuntimeException;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\Events\DsarSlaBreached;
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
