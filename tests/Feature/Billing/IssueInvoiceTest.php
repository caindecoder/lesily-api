<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Billing\Invoices\Aggregates\Invoice;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Billing\Invoices\Events\InvoiceCreated;
use App\Billing\Invoices\Entities\Invoice as InvoiceReadModel;
use App\Billing\Invoices\Exceptions\InvoiceAlreadyIssuedException;
use App\Billing\Invoices\Exceptions\InvoiceAmountNotPositiveException;
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

    #[Test]
    public function when_an_invoice_is_issued_with_a_zero_amount_then_the_issue_is_rejected(): void
    {
        // Given a company with a trial subscription
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoiceId = (string) Str::uuid();

        // When a zero-amount invoice with zero-sum line items is issued (SM-1-04 T1: amount > 0)
        try {
            Invoice::retrieve($invoiceId)
                ->issue($this->issueInvoiceCommand($company, $subscription, [
                    'amountCents' => 0,
                    'lineItems' => [
                        [
                            'description' => 'Pro Plan — Monthly',
                            'quantity' => 1,
                            'unitPriceCents' => 15000,
                            'amountCents' => 15000,
                            'lineType' => 'plan_subscription',
                            'sortOrder' => 0,
                        ],
                        [
                            'description' => 'Proration credit — unused Starter days',
                            'quantity' => -1,
                            'unitPriceCents' => 15000,
                            'amountCents' => -15000,
                            'lineType' => 'proration_credit',
                            'sortOrder' => 1,
                        ],
                    ],
                ]))
                ->persist();

            $this->fail('Expected InvoiceAmountNotPositiveException was not thrown.');
        } catch (InvoiceAmountNotPositiveException $exception) {
            // Then the issue is rejected naming the non-positive amount and no event is stored
            $this->assertStringContainsString('for 0 cents', $exception->getMessage());
            $this->assertNoNewInvoiceEvents($invoiceId, 0);
        }
    }

    #[Test]
    public function when_a_line_item_amount_is_not_an_integer_then_issuing_is_refused(): void
    {
        // Given a company with a trial subscription
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoiceId = (string) Str::uuid();

        // When an invoice is issued whose line items carry a fractional-cents amount that sums to the invoice amount
        try {
            Invoice::retrieve($invoiceId)
                ->issue($this->issueInvoiceCommand($company, $subscription, [
                    'lineItems' => [
                        [
                            'description' => 'Pro Plan — Monthly',
                            'quantity' => 1,
                            'unitPriceCents' => 75000,
                            'amountCents' => 75000.0,
                            'lineType' => 'plan_subscription',
                            'sortOrder' => 0,
                        ],
                        [
                            'description' => 'Proration credit — unused Starter days',
                            'quantity' => -1,
                            'unitPriceCents' => 15000,
                            'amountCents' => -15000,
                            'lineType' => 'proration_credit',
                            'sortOrder' => 1,
                        ],
                    ],
                ]))
                ->persist();

            $this->fail('Expected InvoiceLineItemsMismatchException was not thrown.');
        } catch (InvoiceLineItemsMismatchException $exception) {
            // Then the issue is refused naming the fractional total and no event is stored
            $this->assertStringContainsString('60000.0', $exception->getMessage());
            $this->assertNoNewInvoiceEvents($invoiceId, 0);
        }
    }

    #[Test]
    public function when_an_invoice_is_issued_for_the_minimum_one_cent_then_it_is_accepted(): void
    {
        // Given a company with a trial subscription
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoiceId = (string) Str::uuid();

        // When a one-cent invoice is issued at the domain seam (SM-1-04 T1: amount > 0, boundary)
        Invoice::retrieve($invoiceId)
            ->issue($this->issueInvoiceCommand($company, $subscription, [
                'amountCents' => 1,
                'lineItems' => [
                    [
                        'description' => 'Pro Plan — Monthly',
                        'quantity' => 1,
                        'unitPriceCents' => 1,
                        'amountCents' => 1,
                        'lineType' => 'plan_subscription',
                        'sortOrder' => 0,
                    ],
                ],
            ]))
            ->persist();

        // Then the pending invoice is projected with the one-cent amount
        $this->assertInvoiceRowState($invoiceId, ['status' => 'pending', 'amount_cents' => 1]);
    }

    #[Test]
    public function when_an_invoice_is_issued_in_a_non_default_currency_then_both_projections_carry_it(): void
    {
        // Given a company with a trial subscription
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoiceId = (string) Str::uuid();

        // When an invoice is issued in USD at the domain seam
        Invoice::retrieve($invoiceId)
            ->issue($this->issueInvoiceCommand($company, $subscription, ['currency' => 'USD']))
            ->persist();

        // Then the invoice row and every line item carry USD rather than the column default
        $this->assertInvoiceRowState($invoiceId, ['currency' => 'USD']);
        $this->assertLineItemsProjected($invoiceId, [
            ['sort_order' => 0, 'currency' => 'USD'],
            ['sort_order' => 1, 'currency' => 'USD'],
        ]);
    }

    #[Test]
    public function when_an_invoice_is_issued_then_the_pending_invoice_and_its_line_items_are_projected(): void
    {
        // Given a company with a trial subscription and an acting owner with a billing address
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $owner = User::factory()->create();
        $billingAddress = ['line1' => '12 Galle Road', 'city' => 'Colombo', 'country' => 'LK'];
        $invoiceId = (string) Str::uuid();

        // When an invoice is issued at the domain seam
        Invoice::retrieve($invoiceId)
            ->issue($this->issueInvoiceCommand($company, $subscription, [
                'recipientBillingAddress' => $billingAddress,
                'createdBy' => $owner,
            ]))
            ->persist();

        // Then the creation event is stored and the pending invoice with its line items is projected
        $this->assertInvoiceEventRecorded(InvoiceCreated::class, $invoiceId, [
            'invoiceNumber' => 'INV-2026-000001',
            'subscriptionId' => $subscription->getKey(),
            'companyId' => $company->getKey(),
            'amountCents' => 60000,
            'source' => 'subscribe',
            'createdBy' => $owner->getKey(),
        ]);
        $this->assertInvoiceRowState($invoiceId, [
            'company_id' => $company->getKey(),
            'subscription_id' => $subscription->getKey(),
            'invoice_number' => 'INV-2026-000001',
            'status' => 'pending',
            'source' => 'subscribe',
            'has_dispute' => false,
            'amount_cents' => 60000,
            'currency' => 'LKR',
            'recipient_name' => 'ACME (Pvt) Ltd',
            'recipient_email' => 'billing@acme.example',
            'created_by' => $owner->getKey(),
        ]);
        $this->assertEquals($billingAddress, InvoiceReadModel::query()->findOrFail($invoiceId)->recipient_billing_address);
        $this->assertLineItemsProjected($invoiceId, [
            [
                'description' => 'Pro Plan — Monthly',
                'quantity' => 1,
                'unit_price_cents' => 75000,
                'amount_cents' => 75000,
                'line_type' => 'plan_subscription',
                'sort_order' => 0,
            ],
            [
                'description' => 'Proration credit — unused Starter days',
                'quantity' => -1,
                'unit_price_cents' => 15000,
                'amount_cents' => -15000,
                'line_type' => 'proration_credit',
                'sort_order' => 1,
            ],
        ]);
    }
}
