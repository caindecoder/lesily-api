<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The paid-invoice PDF receipt (UC-1-121, slice-plans-billing T5):
 * GET /api/v1/invoices/{id}/receipt — regenerated on demand, never stored
 * (R3); status ∈ {paid, refunded} AND no open dispute (F7); 404 no-leak
 * cross-tenant; 503 JSON-never-truncated on renderer failure. Read bucket
 * (conv-plan §2.15): controller → repository → renderer port; the aggregate
 * is never touched — state fixtures arrive through the BillingTestCase
 * event seam (B4).
 */
class DownloadInvoiceReceiptTest extends BillingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Error-path fidelity: prod/CI render with debug off (amendment M).
        config(['app.debug' => false]);

        $this->seedRbacCatalog();
    }

    // --- Sad ---

    #[Test]
    #[DataProvider('provideNonPaidStatuses')]
    public function when_invoice_is_not_paid_then_receipt_unavailable_response(string $status): void
    {
        // Given an owner of a company holding an invoice that was never paid
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anInvoiceInState($status, $company, $subscription);

        // When they download its receipt
        $response = $this->downloadReceiptAs($owner, $company, $invoice->getKey());

        // Then the receipt is refused for the non-paid state — 409 naming the
        // stored status, JSON body, no PDF bytes (UC-1-121 alt 5a; s135 F-c)
        $this->assertReceiptStateGuardRejected(
            $response,
            'Receipt is only available for paid invoices.',
            $status,
        );
    }

    #[Test]
    public function when_paid_invoice_has_open_dispute_then_receipt_blocked_response(): void
    {
        // Given an owner whose PAID invoice carries an open dispute
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidDisputedInvoiceFor($company, $subscription);

        // When they download its receipt
        $response = $this->downloadReceiptAs($owner, $company, $invoice->getKey());

        // Then the receipt is blocked while the dispute is open — even though
        // the stored status is paid (UC-1-121 alt 5b, s129 F7; the block clears
        // when dispute-resolution lands with the dispute slice, UC-1-054)
        $this->assertReceiptStateGuardRejected($response, 'Receipt is unavailable while a dispute is open.');
    }

    #[Test]
    public function when_unauthenticated_then_request_rejected(): void
    {
        // Given a company holding a paid invoice
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);

        // When its receipt is requested with no auth token
        $response = $this->getJson("/api/v1/invoices/{$invoice->getKey()}/receipt", [
            'X-Company-Context' => $company->getKey(),
        ]);

        // Then it is refused as unauthenticated — UC-1-121's main flow is never
        // entered (upstream Sanctum, error flow 1a)
        $this->assertUnauthenticated($response);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideNonPaidStatuses(): array
    {
        return [
            'pending invoice' => ['pending'],
            'failed invoice' => ['failed'],
            'voided invoice' => ['voided'],
        ];
    }
}
