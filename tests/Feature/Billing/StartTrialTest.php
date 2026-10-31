<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Subscriptions\Enums\SubscriptionStatus;
use App\Billing\Subscriptions\Events\SubscriptionTrialStarted;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
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
    protected Company $company;

    protected string $companyId;

    protected string $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-01 00:00:00');
        $this->company = Company::factory()->create();
        $this->companyId = $this->company->getKey();
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

    // Happy — genesis: starting a trial stores the trial-started event scoped to the
    // owning company AND projects the trial subscription row, in one walk.
    #[Test]
    public function when_a_trial_is_started_then_the_genesis_is_stored_in_the_owning_company_scope_and_projected(): void
    {
        // Given a fixed point in time and a new subscription id for a company (setUp)

        // When the trial is started and persisted
        $this->startTrial();

        // Then exactly one event is stored in the owning company scope, and the row is projected
        $event = LesilyStoredEvent::query()->whereForCompany($this->companyId)->sole();
        $row = SubscriptionReadModel::findOrFail($this->subscriptionId);

        $this->assertTrialStartedEventRecorded($event);
        $this->assertTrialSubscriptionProjected($row);
    }

    protected function startTrial(): void
    {
        $command = StartTrialCommand::from([
            'company' => $this->company,
            'trialEndsAt' => CarbonImmutable::now()->addDays(30),
        ]);

        Subscription::retrieve($this->subscriptionId)->startTrial($command)->persist();
    }

    protected function assertTrialGenesisUnchanged(): void
    {
        $this->assertSame(1, LesilyStoredEvent::query()->whereForCompany($this->companyId)->count());
        $this->assertSame(SubscriptionStatus::Trial, SubscriptionReadModel::findOrFail($this->subscriptionId)->status);
    }

    protected function assertTrialStartedEventRecorded(EloquentStoredEvent $event): void
    {
        $this->assertSame(SubscriptionTrialStarted::class, $event->event_class);
        $this->assertSame($this->companyId, $event->event_properties['companyId']);
        $this->assertSame('2026-07-01T00:00:00+00:00', $event->event_properties['trialEndsAt']);
    }

    protected function assertTrialSubscriptionProjected(SubscriptionReadModel $row): void
    {
        $this->assertSame($this->companyId, $row->company_id);
        $this->assertSame(SubscriptionStatus::Trial, $row->status);
        $this->assertSame('2026-07-01 00:00:00', $row->trial_ends_at?->toDateTimeString());
    }
}
