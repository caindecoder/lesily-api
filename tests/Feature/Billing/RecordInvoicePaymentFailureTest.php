<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Billing\Invoices\Aggregates\Invoice;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        } catch (InvoiceNotPayableException) {
            // Then the failure is rejected and no new event is stored
            $this->assertNoNewInvoiceEvents($invoice->getKey(), $storedEventCount);
        }
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
