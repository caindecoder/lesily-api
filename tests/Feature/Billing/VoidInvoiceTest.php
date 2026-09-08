<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Billing\Invoices\Aggregates\Invoice;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Billing\Invoices\Events\InvoiceVoided;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Billing\Invoices\Exceptions\InvoiceNotVoidableException;

/**
 * Voiding an unpaid invoice at the domain seam — SM-1-04 T7 (Pending → Voided)
 * and T8 (Failed → Voided) plus the no-charge-collected guard.
 *
 * Domain-seam test class (conv-plan §2.14 Rule C): the Invoice aggregate ships
 * caller-less at HTTP by declared design (slice-#10 B4 — the admin/dispute
 * producers land with later slices), so these guards are provable only at this
 * seam; the walks protect the future producers and replay integrity.
 */
class VoidInvoiceTest extends BillingTestCase
{
    #[Test, DataProvider('provideNonVoidableStates')]
    public function when_a_paid_or_terminal_invoice_is_voided_then_the_void_is_rejected(
        string $state,
        int $storedEventCount,
    ): void {
        // Given an invoice in a state that cannot be voided
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anInvoiceInState($state, $subscription);

        // When an admin voids it
        try {
            Invoice::retrieve($invoice->getKey())
                ->void($this->voidInvoiceCommand($invoice))
                ->persist();

            $this->fail('Expected InvoiceNotVoidableException was not thrown.');
        } catch (InvoiceNotVoidableException $exception) {
            // Then the void is rejected naming the blocking state and no new event is stored
            $this->assertStringContainsString("while {$state}", $exception->getMessage());
            $this->assertNoNewInvoiceEvents($invoice->getKey(), $storedEventCount);
        }
    }

    #[Test]
    public function when_a_pending_invoice_is_voided_then_the_invoice_is_voided(): void
    {
        // Given a pending invoice and the admin correcting it
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anIssuedInvoiceFor($company, $subscription);
        $admin = User::factory()->create();

        // When the admin voids it as issued-in-error
        Invoice::retrieve($invoice->getKey())
            ->void($this->voidInvoiceCommand($invoice, ['voidedBy' => $admin->getKey()]))
            ->persist();

        // Then the void event is stored and the row reflects the terminal void
        $this->assertInvoiceEventRecorded(InvoiceVoided::class, $invoice->getKey(), [
            'voidedBy' => $admin->getKey(),
            'source' => 'admin-correction',
        ]);
        $this->assertInvoiceRowState($invoice->getKey(), [
            'status' => 'voided',
            'voided_by' => $admin->getKey(),
            'void_reason' => 'Issued in error - admin correction.',
            'void_source' => 'admin-correction',
        ]);
        $this->assertNotNull($invoice->fresh()?->voided_at);
    }

    #[Test]
    public function when_a_failed_invoice_is_voided_then_the_invoice_is_voided(): void
    {
        // Given an invoice in dunning whose dispute resolved before any successful retry
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aFailedInvoiceFor($company, $subscription);

        // When it is voided pre-payment
        Invoice::retrieve($invoice->getKey())
            ->void($this->voidInvoiceCommand($invoice, [
                'source' => 'dispute-pre-payment',
                'reason' => 'Dispute resolved - invoice should not have been issued.',
            ]))
            ->persist();

        // Then the void event is stored and the dunning invoice is terminally voided
        $this->assertInvoiceEventRecorded(InvoiceVoided::class, $invoice->getKey(), [
            'source' => 'dispute-pre-payment',
        ]);
        $this->assertInvoiceRowState($invoice->getKey(), [
            'status' => 'voided',
            'void_source' => 'dispute-pre-payment',
            'failure_count' => 1,
        ]);
    }

    /**
     * States that must reject a void, with the number of stored events their
     * fixture arrangement leaves on the stream.
     *
     * @return array<string, array{string, int}>
     */
    public static function provideNonVoidableStates(): array
    {
        return [
            'paid' => ['paid', 2],
            'refunded' => ['refunded', 3],
            'voided' => ['voided', 2],
        ];
    }
}
