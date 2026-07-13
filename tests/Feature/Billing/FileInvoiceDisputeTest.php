<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Billing\Invoices\Aggregates\Invoice;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Billing\Invoices\Events\InvoiceDisputeFiled;
use App\Billing\Invoices\Exceptions\InvoiceDisputeNotAllowedException;

/**
 * Filing a dispute at the domain seam — the SM-1-04 modifier event (no state
 * transition; `has_dispute` flag only, s133 F8) with the one-open-dispute and
 * terminal-state guards. Resolution events ride the dispute slice (UC-1-054).
 *
 * Domain-seam test class (conv-plan §2.14 Rule C): the Invoice aggregate ships
 * caller-less at HTTP by declared design (slice-#10 B4 — the dispute producers
 * land with later slices), so these guards are provable only at this seam; the
 * walks protect the future producers and replay integrity.
 */
class FileInvoiceDisputeTest extends BillingTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_a_dispute_is_filed_on_an_invoice_with_an_open_dispute_then_the_filing_is_rejected(): void
    {
        // Given an invoice that already has an open dispute
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aDisputedInvoiceFor($company, $subscription);

        // When a second dispute is filed against it
        try {
            Invoice::retrieve($invoice->getKey())
                ->fileDispute($this->fileInvoiceDisputeCommand($invoice))
                ->persist();

            $this->fail('Expected InvoiceDisputeNotAllowedException was not thrown.');
        } catch (InvoiceDisputeNotAllowedException) {
            // Then the filing is rejected and no new event is stored
            $this->assertNoNewInvoiceEvents($invoice->getKey(), 2);
        }
    }

    #[Test, DataProvider('provideNonDisputableStates')]
    public function when_a_dispute_is_filed_on_a_terminal_invoice_then_the_filing_is_rejected(
        string $state,
        int $storedEventCount,
    ): void {
        // Given a terminally settled invoice
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anInvoiceInState($state, $company, $subscription);

        // When a dispute is filed against it
        try {
            Invoice::retrieve($invoice->getKey())
                ->fileDispute($this->fileInvoiceDisputeCommand($invoice))
                ->persist();

            $this->fail('Expected InvoiceDisputeNotAllowedException was not thrown.');
        } catch (InvoiceDisputeNotAllowedException) {
            // Then the filing is rejected and no new event is stored
            $this->assertNoNewInvoiceEvents($invoice->getKey(), $storedEventCount);
        }
    }

    #[Test]
    public function when_a_dispute_is_filed_then_the_dispute_flag_is_projected_without_a_state_change(): void
    {
        // Given a PAID invoice and the owner disputing its charge (F7: even paid invoices dispute)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);
        $owner = User::factory()->create();

        // When the dispute is filed
        Invoice::retrieve($invoice->getKey())
            ->fileDispute($this->fileInvoiceDisputeCommand($invoice, [
                'filedBy' => $owner->getKey(),
                'reason' => 'We were charged after cancelling the add-on.',
            ]))
            ->persist();

        // Then the modifier event is stored and only the dispute flag changes on the row
        $this->assertInvoiceEventRecorded(InvoiceDisputeFiled::class, $invoice->getKey(), [
            'filedBy' => $owner->getKey(),
            'reason' => 'We were charged after cancelling the add-on.',
        ]);
        $this->assertInvoiceRowState($invoice->getKey(), [
            'status' => 'paid',
            'has_dispute' => true,
        ]);
    }

    #[Test]
    public function when_a_dispute_is_filed_on_a_failed_invoice_then_the_dispute_flag_is_projected_without_a_state_change(): void
    {
        // Given an invoice in dunning (UC-1-054's primary flow — disputes arrive while failed)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aFailedInvoiceFor($company, $subscription);

        // When the owner disputes the failed charge
        Invoice::retrieve($invoice->getKey())
            ->fileDispute($this->fileInvoiceDisputeCommand($invoice))
            ->persist();

        // Then the dispute event is stored and only the flag changes — dunning state preserved
        $this->assertInvoiceEventRecorded(InvoiceDisputeFiled::class, $invoice->getKey(), []);
        $this->assertInvoiceRowState($invoice->getKey(), [
            'status' => 'failed',
            'has_dispute' => true,
            'failure_count' => 1,
        ]);
    }

    /**
     * Terminal states that must reject a dispute filing (SM-1-04: disputes
     * attach to pending, failed or paid invoices only), with the number of
     * stored events their fixture arrangement leaves on the stream.
     *
     * @return array<string, array{string, int}>
     */
    public static function provideNonDisputableStates(): array
    {
        return [
            'refunded' => ['refunded', 3],
            'voided' => ['voided', 2],
        ];
    }
}
