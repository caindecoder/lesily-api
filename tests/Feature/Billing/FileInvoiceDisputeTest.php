<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Billing\Invoices\Aggregates\Invoice;
use App\Foundation\Billing\Enums\BillingPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
