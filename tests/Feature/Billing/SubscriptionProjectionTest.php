<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Subscriptions\Enums\SubscriptionStatus;
use App\Billing\Subscriptions\UseCases\Commands\StartTrialCommand;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;

class SubscriptionProjectionTest extends BillingTestCase
{
    use RefreshDatabase;

    // Happy — the read side mirrors a genesis: starting a trial projects a trial subscription row.
    #[Test]
    public function when_a_trial_is_started_then_a_trial_subscription_row_is_projected_for_the_company(): void
    {
        // Given a fixed point in time, a company, and a new subscription id
        $this->travelTo('2026-06-01 00:00:00');
        $companyId = (string) Str::uuid();
        $subscriptionId = (string) Str::uuid();

        // When the trial is started and persisted (the projector runs synchronously)
        $command = StartTrialCommand::validateAndCreate([
            'companyId' => $companyId,
            'trialEndsAt' => CarbonImmutable::now()->addDays(30),
        ]);
        Subscription::retrieve($subscriptionId)->startTrial($command)->persist();

        // Then the read model shows a trial subscription for the company expiring in 30 days
        $row = SubscriptionReadModel::findOrFail($subscriptionId);

        $this->assertSubscriptionOnTrial($row, $companyId, '2026-07-01 00:00:00');
    }

    protected function assertSubscriptionOnTrial(SubscriptionReadModel $row, string $companyId, string $trialEndsAt): void
    {
        $this->assertSame($companyId, $row->company_id);
        $this->assertSame(SubscriptionStatus::Trial, $row->status);
        $this->assertSame($trialEndsAt, $row->trial_ends_at?->toDateTimeString());
    }
}
