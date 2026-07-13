<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Billing\Invoices\Aggregates\Invoice;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Billing\Invoices\Events\InvoiceRefunded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Billing\Invoices\Exceptions\InvoiceNotRefundableException;
use App\Billing\Invoices\Exceptions\InvoiceRefundAmountMismatchException;

/**
 * Refunding an invoice at the domain seam — SM-1-04 T5 (Failed → Refunded
 * credit memo) and T6 (Paid → Refunded), the illegal-state guard and the
 * chunk-1 full-refund-only amount guard.
 *
 * Domain-seam test class (conv-plan §2.14 Rule C): the Invoice aggregate ships
 * caller-less at HTTP by declared design (slice-#10 B4 — the dispute/admin
 * producers land with later slices), so these guards are provable only at this
 * seam; the walks protect the future producers and replay integrity.
 */
class RefundInvoiceTest extends BillingTestCase
{
    use RefreshDatabase;

    #[Test, DataProvider('provideNonRefundableStates')]
    public function when_a_pending_or_voided_or_refunded_invoice_is_refunded_then_the_refund_is_rejected(
        string $state,
        int $storedEventCount,
    ): void {
        // Given an invoice in a state that cannot be refunded
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anInvoiceInState($state, $company, $subscription);

        // When a full refund is issued against it
        try {
            Invoice::retrieve($invoice->getKey())
                ->refund($this->refundInvoiceCommand($invoice))
                ->persist();

            $this->fail('Expected InvoiceNotRefundableException was not thrown.');
        } catch (InvoiceNotRefundableException) {
            // Then the refund is rejected and no new event is stored
            $this->assertNoNewInvoiceEvents($invoice->getKey(), $storedEventCount);
        }
    }

    #[Test]
    public function when_the_refund_amount_differs_from_the_invoice_amount_then_the_refund_is_rejected(): void
    {
        // Given a paid invoice
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);

        // When a partial refund is attempted (chunk-1 is full-refund-only)
        try {
            Invoice::retrieve($invoice->getKey())
                ->refund($this->refundInvoiceCommand($invoice, ['refundAmountCents' => 1000]))
                ->persist();

            $this->fail('Expected InvoiceRefundAmountMismatchException was not thrown.');
        } catch (InvoiceRefundAmountMismatchException) {
            // Then the refund is rejected and no new event is stored
            $this->assertNoNewInvoiceEvents($invoice->getKey(), 2);
        }
    }

    #[Test]
    public function when_a_paid_invoice_is_refunded_then_the_invoice_is_refunded(): void
    {
        // Given a paid invoice
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);

        // When platform support issues a full refund through PayHere
        Invoice::retrieve($invoice->getKey())
            ->refund($this->refundInvoiceCommand($invoice, [
                'payHereRefundTransactionId' => 'refund_txn_t6',
            ]))
            ->persist();

        // Then the refund event is stored and the row reflects the terminal refund
        $this->assertInvoiceEventRecorded(InvoiceRefunded::class, $invoice->getKey(), [
            'refundAmountCents' => 60000,
            'payHereRefundTransactionId' => 'refund_txn_t6',
            'source' => 'admin-refund',
        ]);
        $this->assertInvoiceRowState($invoice->getKey(), [
            'status' => 'refunded',
            'refund_amount_cents' => 60000,
            'payhere_refund_transaction_id' => 'refund_txn_t6',
            'refund_source' => 'admin-refund',
            'refund_reason' => 'Goodwill refund issued by platform support.',
        ]);
        $this->assertNotNull($invoice->fresh()?->refunded_at);
    }

    #[Test]
    public function when_a_failed_invoice_is_credited_then_the_invoice_is_refunded_without_a_payment_reference(): void
    {
        // Given an invoice whose payment failed and whose dispute was resolved in the owner's favor
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aFailedInvoiceFor($company, $subscription);

        // When the full credit memo is recorded (no money ever moved — no PayHere refund)
        Invoice::retrieve($invoice->getKey())
            ->refund($this->refundInvoiceCommand($invoice, [
                'payHereRefundTransactionId' => null,
                'source' => 'dispute-resolved-in-favor',
                'reason' => 'Dispute resolved in favor of the owner.',
            ]))
            ->persist();

        // Then the credit is recorded as a refund with no payment reference
        $this->assertInvoiceEventRecorded(InvoiceRefunded::class, $invoice->getKey(), [
            'refundAmountCents' => 60000,
            'source' => 'dispute-resolved-in-favor',
        ]);
        $this->assertInvoiceRowState($invoice->getKey(), [
            'status' => 'refunded',
            'refund_amount_cents' => 60000,
            'payhere_refund_transaction_id' => null,
            'refund_source' => 'dispute-resolved-in-favor',
        ]);
    }

    /**
     * States that must reject a refund, with the number of stored events their
     * fixture arrangement leaves on the stream.
     *
     * @return array<string, array{string, int}>
     */
    public static function provideNonRefundableStates(): array
    {
        return [
            'pending' => ['pending', 1],
            'voided' => ['voided', 2],
            'refunded' => ['refunded', 3],
        ];
    }
}
