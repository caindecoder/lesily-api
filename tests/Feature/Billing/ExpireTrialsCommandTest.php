<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use App\Companies\Aggregates\Company;
use App\Companies\Enums\CompanyStatus;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Subscriptions\Enums\SubscriptionStatus;
use App\Companies\Entities\Company as CompanyReadModel;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;

class ExpireTrialsCommandTest extends BillingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00:00'));
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

    protected function givenTrialEndingAt(string $companyId, string $subscriptionId, CarbonInterface $trialEndsAt): void
    {
        Company::retrieve($companyId)->create('Contoso')->persist();
        Subscription::retrieve($subscriptionId)->startTrial($companyId, $trialEndsAt)->persist();
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
}
