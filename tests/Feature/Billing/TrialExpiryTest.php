<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Companies\Aggregates\Company;
use App\Companies\Enums\CompanyStatus;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Events\CompanyTrialExpired;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Subscriptions\Enums\SubscriptionStatus;
use App\Companies\Entities\Company as CompanyReadModel;
use App\Billing\Subscriptions\Events\SubscriptionTrialEnded;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Billing\Subscriptions\UseCases\Commands\ApplyTrialExpirationCommand;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;
use App\Billing\Subscriptions\UseCases\Interactors\ApplyTrialExpirationInteractor;

class TrialExpiryTest extends BillingTestCase
{
    use RefreshDatabase;

    // Sad/guard (UC-1-049 Sc5 guard) — once a trial has ended, expiring it again is a no-op.
    // This is the status guard that also skips a company that already subscribed.
    #[Test]
    public function when_an_already_expired_trial_is_expired_again_then_nothing_changes(): void
    {
        // Given a company and subscription whose trial has already been expired once
        $companyId = (string) Str::uuid();
        $subscriptionId = (string) Str::uuid();
        $this->givenCompanyAndSubscriptionOnTrial($companyId, $subscriptionId);
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

    // Happy (UC-1-049 Sc1) — an elapsed trial flips the company to read-only and ends its subscription.
    #[Test]
    public function when_a_trial_has_elapsed_then_the_company_is_read_only_and_the_subscription_is_cancelled(): void
    {
        // Given a company and its subscription on trial
        $companyId = (string) Str::uuid();
        $subscriptionId = (string) Str::uuid();
        $this->givenCompanyAndSubscriptionOnTrial($companyId, $subscriptionId);

        // When the trial-expiry interactor runs for them
        app(ApplyTrialExpirationInteractor::class)->execute(
            ApplyTrialExpirationCommand::validateAndCreate(['companyId' => $companyId, 'subscriptionId' => $subscriptionId]),
        );

        // Then the company is read-only, the subscription ended as trial-expired, and both events stay company-scoped
        $this->assertCompanyTrialExpired($companyId);
        $this->assertSubscriptionCancelledAsTrialExpired($subscriptionId);
        $this->assertExpiryEventsAreCompanyScoped($companyId);
    }

    protected function givenCompanyAndSubscriptionOnTrial(string $companyId, string $subscriptionId): void
    {
        Company::retrieve($companyId)->create('Contoso')->persist();
        Subscription::retrieve($subscriptionId)->startTrial($companyId, CarbonImmutable::now()->addDays(30))->persist();
    }

    protected function assertCompanyTrialExpired(string $companyId): void
    {
        $this->assertSame(CompanyStatus::TrialExpired, CompanyReadModel::findOrFail($companyId)->status);
    }

    protected function assertSubscriptionCancelledAsTrialExpired(string $subscriptionId): void
    {
        $subscription = SubscriptionReadModel::findOrFail($subscriptionId);

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->status);
        $this->assertSame('trial-expired', $subscription->ended_source);
        $this->assertNotNull($subscription->cancelled_at);
    }

    protected function assertExpiryEventsAreCompanyScoped(string $companyId): void
    {
        $scopedEventClasses = LesilyStoredEvent::query()->whereForCompany($companyId)->pluck('event_class')->all();

        $this->assertContains(CompanyTrialExpired::class, $scopedEventClasses);
        $this->assertContains(SubscriptionTrialEnded::class, $scopedEventClasses);
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
}
