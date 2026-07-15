<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
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

    #[Test]
    #[DataProvider('provideDeniedRoles')]
    public function when_role_lacks_billing_view_then_role_insufficient_response(string $roleCode): void
    {
        // Given a member whose role does not carry billing.view (2026-05-24 matrix)
        // and a paid invoice in their company
        [$company, $actor] = $this->aCompanyWithMember($roleCode);
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);

        // When they download its receipt
        $response = $this->downloadReceiptAs($actor, $company, $invoice->getKey());

        // Then the download is denied for the missing permission — no PDF bytes,
        // no invoice fields in the body (UC-1-121 error flow 3a)
        $this->assertRoleInsufficient($response, 'billing.view');
    }

    #[Test]
    public function when_company_context_is_foreign_then_company_access_denied(): void
    {
        // Given a member of one company and a second company they never joined
        [, $outsider] = $this->aCompanyWithMember('owner');
        [$companyB] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($companyB, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($companyB, $subscription);

        // When they request that company's receipt under its context
        $response = $this->downloadReceiptAs($outsider, $companyB, $invoice->getKey());

        // Then the company context is denied (AuthorizeCompany membership check —
        // UC-1-121 tenant isolation, first gate)
        $this->assertCompanyAccessDenied($response);
    }

    #[Test]
    public function when_invoice_is_unknown_then_not_found_response(): void
    {
        // Given an owner whose company holds no such invoice
        [$company, $owner] = $this->aCompanyWithMember('owner');

        // When they download a receipt for a random invoice id
        $response = $this->downloadReceiptAs($owner, $company, (string) Str::uuid());

        // Then it is refused as not found (UC-1-121 error flow 4a)
        $this->assertInvoiceNotFound($response);
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
