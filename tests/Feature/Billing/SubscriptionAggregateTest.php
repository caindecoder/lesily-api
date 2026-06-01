<?php

namespace Tests\Feature\Billing;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Subscriptions\Events\SubscriptionTrialStarted;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Billing\Subscriptions\Exceptions\SubscriptionAlreadyStartedException;

class SubscriptionAggregateTest extends BillingTestCase
{
    use RefreshDatabase;

    // Sad — a trial can only be started once: re-starting an existing subscription is rejected.
    #[Test]
    public function when_a_subscription_that_already_started_a_trial_starts_one_again_then_it_is_rejected(): void
    {
        // Given a subscription that already started a trial
        $companyId = (string) Str::uuid();
        $subscriptionId = (string) Str::uuid();
        Subscription::retrieve($subscriptionId)->startTrial($companyId, 30)->persist();

        // When the same subscription starts a trial again, Then it is rejected
        $this->expectException(SubscriptionAlreadyStartedException::class);

        Subscription::retrieve($subscriptionId)->startTrial($companyId, 30);
    }

    // Happy — genesis: starting a trial opens a 30-day trial scoped to the owning company.
    #[Test]
    public function when_a_trial_is_started_then_a_subscription_trial_started_event_is_stored_in_the_owning_company_scope(): void
    {
        // Given a new subscription for a company
        $companyId = (string) Str::uuid();
        $subscriptionId = (string) Str::uuid();

        // When the trial is started and persisted
        Subscription::retrieve($subscriptionId)->startTrial($companyId, 30)->persist();

        // Then a SubscriptionTrialStarted event is stored, scoped to the owning company, for 30 days
        $event = LesilyStoredEvent::query()->whereForCompany($companyId)->sole();

        $this->assertSubscriptionTrialStarted($event, $companyId, 30);
    }

    protected function assertSubscriptionTrialStarted(EloquentStoredEvent $event, string $expectedCompanyId, int $expectedTrialDays): void
    {
        $this->assertSame(SubscriptionTrialStarted::class, $event->event_class);
        $this->assertSame($expectedCompanyId, $event->event_properties['companyId']);
        $this->assertSame($expectedTrialDays, $event->event_properties['trialDays']);
    }
}
