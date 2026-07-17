<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Subscriptions\Enums\SubscriptionStatus;
use App\Billing\Subscriptions\UseCases\Commands\StartTrialCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;
use App\Billing\Subscriptions\Exceptions\SubscriptionAlreadyStartedException;

/**
 * Trial genesis at the domain seam (conv-plan §2.14 Rule C): company genesis mints a
 * fresh subscription UUID, so the trial re-start guard is unreachable via HTTP — it
 * protects future callers and replay integrity, and is provable only at this seam.
 */
class StartTrialTest extends BillingTestCase
{
    use RefreshDatabase;

    protected string $companyId;

    protected string $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-01 00:00:00');
        $this->companyId = (string) Str::uuid();
        $this->subscriptionId = (string) Str::uuid();
    }

    // Sad — a trial can only be started once: re-starting an existing subscription is
    // rejected, and the rejection persists nothing new.
    #[Test]
    public function when_a_subscription_that_already_started_a_trial_starts_one_again_then_it_is_rejected(): void
    {
        // Given a subscription that already started a trial
        $this->startTrial();

        // When the same subscription starts a trial again
        try {
            $this->startTrial();

            $this->fail('Expected SubscriptionAlreadyStartedException to be thrown.');
        } catch (SubscriptionAlreadyStartedException) {
            // Then it is rejected and the genesis is unchanged
            $this->assertTrialGenesisUnchanged();
        }
    }

    protected function startTrial(): void
    {
        $command = StartTrialCommand::validateAndCreate([
            'companyId' => $this->companyId,
            'trialEndsAt' => CarbonImmutable::now()->addDays(30),
        ]);

        Subscription::retrieve($this->subscriptionId)->startTrial($command)->persist();
    }

    protected function assertTrialGenesisUnchanged(): void
    {
        $this->assertSame(1, LesilyStoredEvent::query()->whereForCompany($this->companyId)->count());
        $this->assertSame(SubscriptionStatus::Trial, SubscriptionReadModel::findOrFail($this->subscriptionId)->status);
    }
}
