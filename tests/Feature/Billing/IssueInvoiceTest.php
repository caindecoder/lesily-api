<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Billing\Invoices\Aggregates\Invoice;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Billing\Invoices\Exceptions\InvoiceAlreadyIssuedException;
use App\Billing\Invoices\Exceptions\InvoiceLineItemsMismatchException;

/**
 * Issuing an invoice at the domain seam — genesis guards + the projected
 * pending row (SM-1-04 T1).
 *
 * Domain-seam test class (conv-plan §2.14 Rule C): the Invoice aggregate ships
 * caller-less at HTTP by declared design (slice-#10 B4 — producers land with the
 * payment slices), so its genesis and illegal-transition guards are provable only
 * at this seam; these walks protect the future producers and replay integrity.
 */
class IssueInvoiceTest extends BillingTestCase
{
    #[Test]
    public function when_an_issued_invoice_is_issued_again_then_the_reissue_is_rejected(): void
    {
        // Given an issued invoice
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoiceId = (string) Str::uuid();
        Invoice::retrieve($invoiceId)
            ->issue($this->issueInvoiceCommand($company, $subscription))
            ->persist();

        // When the same invoice aggregate is issued again
        try {
            Invoice::retrieve($invoiceId)
                ->issue($this->issueInvoiceCommand($company, $subscription))
                ->persist();

            $this->fail('Expected InvoiceAlreadyIssuedException was not thrown.');
        } catch (InvoiceAlreadyIssuedException) {
            // Then the reissue is rejected and no second creation event is stored
            $this->assertNoNewInvoiceEvents($invoiceId, 1);
        }
    }

    #[Test]
    public function when_line_item_amounts_do_not_sum_to_the_invoice_amount_then_the_issue_is_rejected(): void
    {
        // Given a company with a trial subscription
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoiceId = (string) Str::uuid();

        // When an invoice is issued whose line items do not sum to its amount
        try {
            Invoice::retrieve($invoiceId)
                ->issue($this->issueInvoiceCommand($company, $subscription, ['amountCents' => 99999]))
                ->persist();

            $this->fail('Expected InvoiceLineItemsMismatchException was not thrown.');
        } catch (InvoiceLineItemsMismatchException) {
            // Then the issue is rejected and no event is stored
            $this->assertNoNewInvoiceEvents($invoiceId, 0);
        }
    }
}
