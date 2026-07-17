<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use App\Companies\Enums\CompanyStatus;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use App\Companies\Events\CompanyTrialExpired;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Companies\CreatesTrialCompanies;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Subscriptions\Enums\SubscriptionStatus;
use App\Companies\Entities\Company as CompanyReadModel;
use App\Billing\Subscriptions\Events\SubscriptionTrialEnded;
use App\Billing\Subscriptions\UseCases\Commands\StartTrialCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Billing\Subscriptions\UseCases\Commands\ApplyTrialExpirationCommand;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;
use App\Billing\Subscriptions\UseCases\Interactors\ApplyTrialExpirationInteractor;

/**
 * @testclass-carveout: console-command — the artifact IS the behavior (the trials:expire
 * scheduled scan; conv-plan §2.14 Rule B).
 */
class ExpireTrialsCommandTest extends BillingTestCase
{
    use CreatesTrialCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00:00'));

        // Post-#04 every genesis enrolls its owner, so expiry fires the #08
        // owner-notification reactor; this suite tests expiry mechanics only.
        Notification::fake();
    }

    // Sad (default clock) — with no --date the scan uses "now"; a trial still inside its window
    // is excluded, so a genuinely empty first scan reports zero and changes nothing.
    #[Test]
    public function when_no_trial_has_elapsed_then_the_default_scan_expires_nothing(): void
    {
        // Given a company still within its trial
        $companyId = (string) Str::uuid();
        $this->givenTrialEndingAt($companyId, (string) Str::uuid(), CarbonImmutable::now()->addDay());

        // When the daily scan runs with no --date (defaults to now)
        $this->runExpireTrials()->expectsOutputToContain('0 trial(s) expired.')->assertSuccessful();

        // Then the company is untouched
        $this->assertSame(CompanyStatus::Trial, CompanyReadModel::findOrFail($companyId)->status);
    }

    // Sad (UC-1-049 Sc5 guard / idempotency) — once a trial has been expired, a later daily
    // scan must not re-process it: the cancelled subscription is filtered out of the scan, so
    // the second run reports zero and records no further events.
    #[Test]
    public function when_an_already_expired_trial_is_scanned_again_then_nothing_is_re_expired(): void
    {
        // Given a company whose elapsed trial was already expired by an earlier run
        $companyId = (string) Str::uuid();
        $subscriptionId = (string) Str::uuid();
        $this->givenTrialEndingAt($companyId, $subscriptionId, CarbonImmutable::now()->subDay());
        $this->runExpireTrials()->assertSuccessful();

        // When the daily scan runs again
        // Then nothing is re-expired — zero reported and each expiry event recorded exactly once
        $this->runExpireTrials()->expectsOutputToContain('0 trial(s) expired.')->assertSuccessful();
        $this->assertExpiredExactlyOnce($companyId);
    }

    // Sad/guard (UC-1-049 Sc5 guard) — the aggregate no-op arms, driven through the
    // interactor DIRECTLY: the scan's repository filter (status='trial') excludes a
    // cancelled subscription upstream, so these arms are console-unreachable — they exist
    // for future callers and replay integrity (Subscription::endTrial / Company::trialExpire
    // early returns). Relocated from TrialExpiryTest (retrofit B3).
    #[Test]
    public function when_an_already_expired_trial_is_expired_again_then_nothing_changes(): void
    {
        // Given a company and subscription whose trial has already been expired once
        $companyId = (string) Str::uuid();
        $subscriptionId = (string) Str::uuid();
        $this->givenTrialEndingAt($companyId, $subscriptionId, CarbonImmutable::now()->addDays(30));
        app(ApplyTrialExpirationInteractor::class)->execute(
            ApplyTrialExpirationCommand::validateAndCreate(['companyId' => $companyId, 'subscriptionId' => $subscriptionId]),
        );

        // When the trial-expiry interactor runs again for them
        app(ApplyTrialExpirationInteractor::class)->execute(
            ApplyTrialExpirationCommand::validateAndCreate(['companyId' => $companyId, 'subscriptionId' => $subscriptionId]),
        );

        // Then nothing changes — each expiry event was recorded exactly once
        $this->assertExpiredExactlyOnce($companyId);
    }

    // Edge/selection (UC-1-049 Sc1 selection) — a company is expired only when its trial
    // elapsed strictly before the run cutoff; the within-trial and exactly-at-cutoff rows are
    // the sad/edge cases that force the `trial_ends_at < asOf` filter.
    #[Test]
    #[DataProvider('cutoffBoundaryProvider')]
    public function when_the_command_runs_then_a_company_is_expired_only_if_its_trial_has_elapsed(
        string $trialEndsAt,
        string $asOf,
        CompanyStatus $expectedStatus,
    ): void {
        // Given a company whose trial ends at a known instant
        $companyId = (string) Str::uuid();
        $subscriptionId = (string) Str::uuid();
        $this->givenTrialEndingAt($companyId, $subscriptionId, CarbonImmutable::parse($trialEndsAt));

        // When the daily trials:expire scan runs as of a given date
        $this->runExpireTrials($asOf)->assertSuccessful();

        // Then the company is expired only if its trial elapsed before the cutoff
        $this->assertSame($expectedStatus, CompanyReadModel::findOrFail($companyId)->status);
    }

    // The exact-cutoff row sits at midnight = the parsed --date, so trial_ends_at < asOf is
    // strictly false — this is the row that pins `<` against a `<=` mutation.
    public static function cutoffBoundaryProvider(): array
    {
        return [
            'trial not yet elapsed' => ['2026-08-01 00:00:00', '2026-07-02', CompanyStatus::Trial],
            'trial ends exactly at cutoff' => ['2026-07-01 00:00:00', '2026-07-01', CompanyStatus::Trial],
            'trial elapsed' => ['2026-07-01 00:00:00', '2026-07-02', CompanyStatus::TrialExpired],
        ];
    }

    // Happy (UC-1-049 Sc1) — an elapsed trial ends its subscription as trial-expired; the
    // command reports how many it expired and runs off the default clock (no --date).
    #[Test]
    public function when_a_trial_has_elapsed_then_its_subscription_is_cancelled_as_trial_expired(): void
    {
        // Given a company whose trial elapsed yesterday
        $companyId = (string) Str::uuid();
        $subscriptionId = (string) Str::uuid();
        $this->givenTrialEndingAt($companyId, $subscriptionId, CarbonImmutable::now()->subDay());

        // When the daily scan runs for today (no --date — defaults to now)
        $this->runExpireTrials()->expectsOutputToContain('1 trial(s) expired.')->assertSuccessful();

        // Then the subscription ended as trial-expired
        $this->assertSubscriptionCancelledAsTrialExpired($subscriptionId);
    }

    // Happy (UC-1-049 Sc1, multiple) — one daily run expires every elapsed trial, not just the first.
    #[Test]
    public function when_multiple_trials_have_elapsed_then_all_of_them_are_expired(): void
    {
        // Given two companies whose trials have elapsed
        $firstCompanyId = (string) Str::uuid();
        $secondCompanyId = (string) Str::uuid();
        $this->givenTrialEndingAt($firstCompanyId, (string) Str::uuid(), CarbonImmutable::now()->subDay());
        $this->givenTrialEndingAt($secondCompanyId, (string) Str::uuid(), CarbonImmutable::now()->subDay());

        // When the daily scan runs
        $this->runExpireTrials()->expectsOutputToContain('2 trial(s) expired.')->assertSuccessful();

        // Then both companies are read-only
        $this->assertBothCompaniesExpired($firstCompanyId, $secondCompanyId);
    }

    protected function givenTrialEndingAt(string $companyId, string $subscriptionId, CarbonInterface $trialEndsAt): void
    {
        $this->createCompanyOnTrial($companyId);
        $command = StartTrialCommand::validateAndCreate([
            'companyId' => $companyId,
            'trialEndsAt' => $trialEndsAt,
        ]);
        Subscription::retrieve($subscriptionId)->startTrial($command)->persist();
    }

    protected function runExpireTrials(?string $asOf = null): PendingCommand
    {
        $command = $this->artisan('trials:expire', $asOf !== null ? ['--date' => $asOf] : []);
        assert($command instanceof PendingCommand);

        return $command;
    }

    protected function assertSubscriptionCancelledAsTrialExpired(string $subscriptionId): void
    {
        $subscription = SubscriptionReadModel::findOrFail($subscriptionId);

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->status);
        $this->assertSame('trial-expired', $subscription->ended_source);
        $this->assertNotNull($subscription->cancelled_at);
    }

    protected function assertExpiredExactlyOnce(string $companyId): void
    {
        $companyExpiries = LesilyStoredEvent::query()->whereForCompany($companyId)
            ->where('event_class', CompanyTrialExpired::class)->count();
        $subscriptionEndings = LesilyStoredEvent::query()->whereForCompany($companyId)
            ->where('event_class', SubscriptionTrialEnded::class)->count();

        $this->assertSame(1, $companyExpiries);
        $this->assertSame(1, $subscriptionEndings);
    }

    protected function assertBothCompaniesExpired(string $firstCompanyId, string $secondCompanyId): void
    {
        $this->assertSame(CompanyStatus::TrialExpired, CompanyReadModel::findOrFail($firstCompanyId)->status);
        $this->assertSame(CompanyStatus::TrialExpired, CompanyReadModel::findOrFail($secondCompanyId)->status);
    }
}
