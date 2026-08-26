<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Billing\Invoices\Aggregates\Invoice;
use App\Billing\Invoices\Events\InvoicePaid;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Billing\Invoices\Exceptions\InvoiceNotPayableException;
use App\Billing\Invoices\UseCases\Commands\RecordPaymentSucceededCommand;
use App\Billing\Invoices\Exceptions\InvoicePaymentAmountMismatchException;

/**
 * Recording a successful payment at the domain seam — SM-1-04 T2
 * (Pending → Paid) and T4 (Failed → Paid) plus the illegal-state guard and
 * the PayHere webhook-replay idempotency edge.
 *
 * Domain-seam test class (conv-plan §2.14 Rule C): the Invoice aggregate ships
 * caller-less at HTTP by declared design (slice-#10 B4 — the webhook producers
 * land with the payment slices), so these guards are provable only at this seam;
 * the walks protect the future producers and replay integrity.
 */
class RecordInvoicePaymentTest extends BillingTestCase
{
    use RefreshDatabase;

    #[Test, DataProvider('provideNonPayableStates')]
    public function when_payment_is_recorded_on_a_paid_or_terminal_invoice_then_the_payment_is_rejected(
        string $state,
        int $storedEventCount,
    ): void {
        // Given an invoice in a state that cannot accept a payment
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anInvoiceInState($state, $company, $subscription);

        // When a payment webhook with a fresh event id is recorded against it
        try {
            Invoice::retrieve($invoice->getKey())
                ->recordPaymentSucceeded($this->recordPaymentSucceededCommand($invoice, [
                    'payHereEventId' => 'evt_fresh_replay_guard',
                ]))
                ->persist();

            $this->fail('Expected InvoiceNotPayableException was not thrown.');
        } catch (InvoiceNotPayableException $exception) {
            // Then the payment is rejected naming the blocking state and no new event is stored
            $this->assertStringContainsString("while {$state}", $exception->getMessage());
            $this->assertNoNewInvoiceEvents($invoice->getKey(), $storedEventCount);
        }
    }

    #[Test]
    public function when_payment_is_recorded_for_a_never_issued_invoice_then_the_payment_is_rejected(): void
    {
        // Given an invoice id no aggregate stream was ever issued for
        $orphanInvoiceId = (string) Str::uuid();

        // When a payment webhook targets the unknown invoice
        try {
            Invoice::retrieve($orphanInvoiceId)
                ->recordPaymentSucceeded(RecordPaymentSucceededCommand::from([
                    'invoiceId' => $orphanInvoiceId,
                    'payHereEventId' => 'evt_orphan_stream_guard',
                    'payHereTransactionId' => 'txn_orphan',
                    'amountCents' => 60000,
                ]))
                ->persist();

            $this->fail('Expected InvoiceNotPayableException was not thrown.');
        } catch (InvoiceNotPayableException) {
            // Then the payment is rejected and no orphan stream is genesised
            $this->assertNoNewInvoiceEvents($orphanInvoiceId, 0);
        }
    }

    #[Test]
    public function when_the_recorded_payment_amount_differs_from_the_invoice_amount_then_the_payment_is_rejected(): void
    {
        // Given a pending invoice
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anIssuedInvoiceFor($company, $subscription);

        // When a payment webhook is recorded with a different amount
        try {
            Invoice::retrieve($invoice->getKey())
                ->recordPaymentSucceeded($this->recordPaymentSucceededCommand($invoice, [
                    'amountCents' => 12345,
                ]))
                ->persist();

            $this->fail('Expected InvoicePaymentAmountMismatchException was not thrown.');
        } catch (InvoicePaymentAmountMismatchException) {
            // Then the payment is rejected and no new event is stored
            $this->assertNoNewInvoiceEvents($invoice->getKey(), 1);
        }
    }

    #[Test]
    public function when_a_replayed_payment_webhook_event_is_recorded_again_then_no_duplicate_event_is_stored(): void
    {
        // Given an invoice paid via a specific PayHere webhook event
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anIssuedInvoiceFor($company, $subscription);
        Invoice::retrieve($invoice->getKey())
            ->recordPaymentSucceeded($this->recordPaymentSucceededCommand($invoice, [
                'payHereEventId' => 'evt_replay_001',
            ]))
            ->persist();
        $rowBeforeReplay = $invoice->fresh()?->getAttributes();

        // When PayHere redelivers the same webhook event
        Invoice::retrieve($invoice->getKey())
            ->recordPaymentSucceeded($this->recordPaymentSucceededCommand($invoice, [
                'payHereEventId' => 'evt_replay_001',
            ]))
            ->persist();

        // Then no duplicate event is stored and the projected row is untouched
        $this->assertNoNewInvoiceEvents($invoice->getKey(), 2);
        $this->assertSame($rowBeforeReplay, $invoice->fresh()?->getAttributes());
    }

    #[Test]
    public function when_a_pending_invoice_payment_succeeds_then_the_invoice_is_paid(): void
    {
        // Given a pending invoice
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anIssuedInvoiceFor($company, $subscription);

        // When a verified payment webhook is recorded against it
        Invoice::retrieve($invoice->getKey())
            ->recordPaymentSucceeded($this->recordPaymentSucceededCommand($invoice, [
                'payHereEventId' => 'evt_t2_success',
                'payHereTransactionId' => 'txn_t2_success',
            ]))
            ->persist();

        // Then the paid event is stored and the projected row reflects the payment
        $this->assertInvoiceEventRecorded(InvoicePaid::class, $invoice->getKey(), [
            'amountCents' => 60000,
            'payHereEventId' => 'evt_t2_success',
            'payHereTransactionId' => 'txn_t2_success',
            'source' => 'first-attempt',
        ]);
        $this->assertInvoiceRowState($invoice->getKey(), [
            'status' => 'paid',
            'payhere_transaction_id' => 'txn_t2_success',
        ]);
        $this->assertNotNull($invoice->fresh()?->paid_at);
    }

    #[Test]
    public function when_a_failed_invoice_payment_succeeds_then_the_invoice_recovers_to_paid(): void
    {
        // Given an invoice whose first payment attempt failed
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aFailedInvoiceFor($company, $subscription);

        // When a retry payment webhook succeeds
        Invoice::retrieve($invoice->getKey())
            ->recordPaymentSucceeded($this->recordPaymentSucceededCommand($invoice, [
                'payHereEventId' => 'evt_t4_recovery',
                'payHereTransactionId' => 'txn_t4_recovery',
            ]))
            ->persist();

        // Then the paid event records the recovery and the row keeps its failure history
        $this->assertInvoiceEventRecorded(InvoicePaid::class, $invoice->getKey(), [
            'payHereEventId' => 'evt_t4_recovery',
            'source' => 'retry-recovery',
        ]);
        $this->assertInvoiceRowState($invoice->getKey(), [
            'status' => 'paid',
            'payhere_transaction_id' => 'txn_t4_recovery',
            'failure_count' => 1,
        ]);
    }

    /**
     * States that must reject a fresh payment, with the number of stored events
     * their fixture arrangement leaves on the stream (the no-op baseline).
     *
     * @return array<string, array{string, int}>
     */
    public static function provideNonPayableStates(): array
    {
        return [
            'paid' => ['paid', 2],
            'refunded' => ['refunded', 3],
            'voided' => ['voided', 2],
        ];
    }
}
