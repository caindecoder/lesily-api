<?php

namespace Database\Factories;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use App\Billing\Invoices\Entities\Invoice;
use App\Billing\Invoices\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Subscriptions\UseCases\Commands\StartTrialCommand;

/**
 * Bulk read-model seeding for invoice list/read tests (T4+). The definition
 * mirrors InvoiceProjector's genesis output exactly and each state method
 * stamps exactly the columns its projector arm writes (PAUSE-1 s133 ruling) —
 * the event seam (BillingTestCase fixtures) remains the arrangement path for
 * behavioral walks; this factory exists for pure-read bulk seeding only and
 * creates no line items.
 *
 * NOT yet wired to the entity: T4's first consumer adds `HasFactory` +
 * `newFactory()` on the Invoice read model RED-first (the T3 wiring sat
 * uncovered — codecov/patch 100% gate, Rule #13 root-cause removal).
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'subscription_id' => function (array $attributes) {
                $subscriptionId = (string) Str::uuid();

                Subscription::retrieve($subscriptionId)
                    ->startTrial(StartTrialCommand::from([
                        'company' => Company::query()->findOrFail($attributes['company_id']),
                        'trialEndsAt' => CarbonImmutable::now()->addDays(30),
                    ]))
                    ->persist();

                return $subscriptionId;
            },
            'invoice_number' => fake()->unique()->numerify('INV-2026-######'),
            'status' => InvoiceStatus::Pending,
            'source' => 'subscribe',
            'has_dispute' => false,
            'amount_cents' => 60000,
            'currency' => 'LKR',
            'due_date' => CarbonImmutable::now()->addDays(14),
            'issued_at' => CarbonImmutable::now(),
            'recipient_name' => fake()->company(),
            'recipient_email' => fake()->safeEmail(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(['company_id' => $company->getKey()]);
    }

    public function amountCents(int $amountCents): static
    {
        return $this->state(['amount_cents' => $amountCents]);
    }

    public function paid(): static
    {
        return $this->state([
            'status' => InvoiceStatus::Paid,
            'paid_at' => CarbonImmutable::now(),
            'payhere_transaction_id' => 'txn_'.fake()->unique()->uuid(),
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => InvoiceStatus::Failed,
            'failed_at' => CarbonImmutable::now(),
            'failure_count' => 1,
            'last_failure_code' => 'insufficient_funds',
        ]);
    }

    public function refunded(): static
    {
        return $this->paid()->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Refunded,
            'refunded_at' => CarbonImmutable::now(),
            'refund_amount_cents' => $attributes['amount_cents'],
            'payhere_refund_transaction_id' => 'refund_txn_'.fake()->unique()->uuid(),
            'refund_source' => 'admin-refund',
            'refund_reason' => 'Goodwill refund issued by platform support.',
        ]);
    }

    public function voided(): static
    {
        return $this->state([
            'status' => InvoiceStatus::Voided,
            'voided_at' => CarbonImmutable::now(),
            'voided_by' => User::factory(),
            'void_reason' => 'Issued in error - admin correction.',
            'void_source' => 'admin-correction',
        ]);
    }

    public function disputed(): static
    {
        return $this->state(['has_dispute' => true]);
    }

    /**
     * An intentionally CORRUPT paid row — receipt-eligible but missing its
     * paid timestamp. Integrity drift only a bug could produce: the domain
     * seam always stamps paid_at, so this state exists solely to exercise the
     * UC-1-121 7c pre-render snapshot gate (s135 F-e).
     */
    public function corruptMissingPaidTimestamp(): static
    {
        return $this->paid()->state(['paid_at' => null]);
    }
}
