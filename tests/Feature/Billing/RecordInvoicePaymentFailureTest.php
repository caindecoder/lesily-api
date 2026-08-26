<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Billing\Invoices\Aggregates\Invoice;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Billing\Invoices\Events\InvoicePaymentFailed;
use App\Billing\Invoices\Exceptions\InvoiceNotPayableException;

/**
 * Recording a failed payment attempt at the domain seam — SM-1-04 T3
 * (Pending → Failed), the no-transition retry recording (SM-1-04 §5), the
 * illegal-state guard and the webhook-replay idempotency edge.
 *
 * Domain-seam test class (conv-plan §2.14 Rule C): the Invoice aggregate ships
 * caller-less at HTTP by declared design (slice-#10 B4 — the webhook producers
 * land with the payment slices), so these guards are provable only at this seam;
 * the walks protect the future producers and replay integrity.
 */
class RecordInvoicePaymentFailureTest extends BillingTestCase
{
    use RefreshDatabase;

    #[Test, DataProvider('provideNonFailableStates')]
    public function when_a_payment_failure_is_recorded_on_a_paid_or_terminal_invoice_then_the_failure_is_rejected(
        string $state,
        int $storedEventCount,
    ): void {
        // Given an invoice in a state that cannot accept a payment failure
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anInvoiceInState($state, $company, $subscription);

        // When a failure webhook with a fresh event id is recorded against it
        try {
            Invoice::retrieve($invoice->getKey())
                ->recordPaymentFailed($this->recordPaymentFailedCommand($invoice, [
                    'payHereEventId' => 'evt_fresh_failure_guard',
                ]))
                ->persist();

            $this->fail('Expected InvoiceNotPayableException was not thrown.');
        } catch (InvoiceNotPayableException $exception) {
            // Then the failure is rejected naming the blocking state and no new event is stored
            $this->assertStringContainsString("while {$state}", $exception->getMessage());
            $this->assertNoNewInvoiceEvents($invoice->getKey(), $storedEventCount);
        }
    }

    #[Test]
    public function when_a_replayed_failure_webhook_event_is_recorded_again_then_no_duplicate_event_is_stored(): void
    {
        // Given an invoice whose first payment attempt failed via a specific webhook event
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aFailedInvoiceFor($company, $subscription);
        $rowBeforeReplay = $invoice->fresh()?->getAttributes();

        // When PayHere redelivers the same failure webhook event
        Invoice::retrieve($invoice->getKey())
            ->recordPaymentFailed($this->recordPaymentFailedCommand($invoice))
            ->persist();

        // Then no duplicate event is stored and the projected row is untouched
        $this->assertNoNewInvoiceEvents($invoice->getKey(), 2);
        $this->assertSame($rowBeforeReplay, $invoice->fresh()?->getAttributes());
    }

    #[Test]
    public function when_a_retry_failure_occurs_on_a_failed_invoice_then_the_failure_is_recorded_without_a_transition(): void
    {
        // Given an invoice whose first payment attempt failed
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aFailedInvoiceFor($company, $subscription);

        // When a Day-1 retry fails with a new webhook event and a different code
        Invoice::retrieve($invoice->getKey())
            ->recordPaymentFailed($this->recordPaymentFailedCommand($invoice, [
                'payHereEventId' => 'evt_fail_retry_day1',
                'failureCode' => 'card_expired',
                'attemptNumber' => 2,
            ]))
            ->persist();

        // Then the retry failure is recorded and the row updates in place, still failed
        $this->assertInvoiceEventRecorded(InvoicePaymentFailed::class, $invoice->getKey(), [
            'payHereEventId' => 'evt_fail_retry_day1',
            'failureCode' => 'card_expired',
            'attemptNumber' => 2,
        ]);
        $this->assertInvoiceRowState($invoice->getKey(), [
            'status' => 'failed',
            'failure_count' => 2,
            'last_failure_code' => 'card_expired',
        ]);
    }

    #[Test]
    public function when_a_pending_invoice_first_payment_fails_then_the_invoice_is_failed(): void
    {
        // Given a pending invoice
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anIssuedInvoiceFor($company, $subscription);

        // When the first payment attempt fails
        Invoice::retrieve($invoice->getKey())
            ->recordPaymentFailed($this->recordPaymentFailedCommand($invoice, [
                'payHereEventId' => 'evt_t3_first_failure',
            ]))
            ->persist();

        // Then the failure event carries the invoiced amount and the row enters dunning state
        $this->assertInvoiceEventRecorded(InvoicePaymentFailed::class, $invoice->getKey(), [
            'payHereEventId' => 'evt_t3_first_failure',
            'amountCents' => 60000,
            'failureCode' => 'insufficient_funds',
            'failureMessage' => 'Card has insufficient funds.',
            'attemptNumber' => 1,
        ]);
        $this->assertInvoiceRowState($invoice->getKey(), [
            'status' => 'failed',
            'failure_count' => 1,
            'last_failure_code' => 'insufficient_funds',
        ]);
        $this->assertNotNull($invoice->fresh()?->failed_at);
    }

    #[Test]
    public function when_the_original_failure_webhook_replays_after_recovery_then_no_duplicate_event_is_stored(): void
    {
        // Given an invoice that failed via a specific webhook event and then recovered to paid
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aFailedInvoiceFor($company, $subscription);
        Invoice::retrieve($invoice->getKey())
            ->recordPaymentSucceeded($this->recordPaymentSucceededCommand($invoice, [
                'payHereEventId' => 'evt_recovery',
            ]))
            ->persist();
        $rowBeforeReplay = $invoice->fresh()?->getAttributes();

        // When PayHere redelivers the ORIGINAL failure webhook against the now-paid invoice
        Invoice::retrieve($invoice->getKey())
            ->recordPaymentFailed($this->recordPaymentFailedCommand($invoice))
            ->persist();

        // Then the redelivery is absorbed — no error, no event, row untouched (SM-1-04 §6)
        $this->assertNoNewInvoiceEvents($invoice->getKey(), 3);
        $this->assertSame($rowBeforeReplay, $invoice->fresh()?->getAttributes());
    }

    /**
     * States that must reject a payment-failure record, with the number of
     * stored events their fixture arrangement leaves on the stream.
     *
     * @return array<string, array{string, int}>
     */
    public static function provideNonFailableStates(): array
    {
        return [
            'paid' => ['paid', 2],
            'refunded' => ['refunded', 3],
            'voided' => ['voided', 2],
        ];
    }
}
