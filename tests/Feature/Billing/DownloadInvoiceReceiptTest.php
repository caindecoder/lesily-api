<?php

namespace Tests\Feature\Billing;

use RuntimeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\LaravelPdf\Facades\Pdf;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Billing\Invoices\Entities\Invoice as InvoiceReadModel;
use App\Billing\Invoices\Exceptions\ReceiptRenderingFailedException;

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
    public function when_refunded_invoice_has_open_dispute_then_receipt_blocked_response(): void
    {
        // Given an owner whose disputed invoice was refunded while the dispute
        // stayed open (reachable: refund() runs from Paid regardless of dispute)
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aRefundedDisputedInvoiceFor($company, $subscription);

        // When they download its receipt
        $response = $this->downloadReceiptAs($owner, $company, $invoice->getKey());

        // Then the dispute block holds for refunded exactly as for paid — a
        // guard narrowed to Paid-only would leak this receipt (UC-1-121 5b;
        // s129 F7 "any stored status"; test-analyzer MINOR-5)
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
        $this->assertStringStartsNotWith('%PDF', (string) $response->getContent());
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

    #[Test]
    public function when_invoice_belongs_to_another_company_then_not_found_response(): void
    {
        // Given another company's PAID invoice seeded FIRST (deterministic scope
        // proof — s131 precedent), and an owner of a different company
        [$companyB] = $this->aCompanyWithMember('owner');
        $subscriptionB = $this->aTrialSubscriptionFor($companyB, CarbonImmutable::now()->addDays(30));
        $foreignInvoice = $this->aPaidInvoiceFor($companyB, $subscriptionB);
        [$companyA, $owner] = $this->aCompanyWithMember('owner');

        // When they probe the foreign invoice id under their OWN company context
        $response = $this->downloadReceiptAs($owner, $companyA, $foreignInvoice->getKey());

        // Then it is refused as not found — indistinguishable from a genuinely
        // missing id, zero PDF bytes (UC-1-121 4a no-existence-leak; the shared
        // helper keeps this response and the unknown-id response from drifting)
        $this->assertInvoiceNotFound($response);
    }

    #[Test]
    public function when_renderer_fails_then_receipt_generation_unavailable_response(): void
    {
        // Given an owner with a paid invoice and a renderer that cannot render
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);
        $this->bindThrowingReceiptRenderer();

        // When they download the receipt
        $response = $this->downloadReceiptAs($owner, $company, $invoice->getKey());

        // Then the failure is a JSON service-unavailable envelope with a retry
        // hint — never a truncated PDF stream (UC-1-121 7b; s135 F-d)
        $this->assertReceiptGenerationUnavailable($response);
    }

    #[Test]
    public function when_rendering_engine_fails_then_receipt_generation_unavailable_response(): void
    {
        // Given an owner with a paid invoice and a PDF engine that throws inside
        // the REAL adapter (the facade IS the library boundary — E23; no stub)
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);
        Pdf::shouldReceive('view')->once()->andThrow(new RuntimeException('Chromium process died'));
        Exceptions::fake();

        // When they download the receipt
        $response = $this->downloadReceiptAs($owner, $company, $invoice->getKey());

        // Then the adapter translates the engine failure into the app-owned 503
        // with the original cause chained for ops and never leaked to the client
        // (code-reviewer C1 — pins the firewall's translation contract)
        $this->assertReceiptGenerationUnavailable($response);
        Exceptions::assertReported(
            fn (ReceiptRenderingFailedException $e): bool => $e->getPrevious() instanceof RuntimeException
        );
    }

    #[Test]
    public function when_rendering_engine_returns_no_document_then_receipt_generation_unavailable_response(): void
    {
        // Given an owner with a paid invoice and a PDF engine that yields an
        // empty render instead of throwing (library-misbehavior edge)
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);
        $emptyRender = new class extends PdfBuilder
        {
            public function base64(): string
            {
                return '';
            }
        };
        Pdf::shouldReceive('view')->andReturn($emptyRender);

        // When they download the receipt
        $response = $this->downloadReceiptAs($owner, $company, $invoice->getKey());

        // Then the incomplete document is refused as unavailable — a client
        // must never receive a 200 with an empty/garbage "PDF" (code-reviewer
        // M4 defense-in-depth on the JSON-never-truncated contract)
        $this->assertReceiptGenerationUnavailable($response);
    }

    #[Test]
    public function when_paid_invoice_snapshot_is_corrupt_then_generic_failure_reported(): void
    {
        // Given a paid invoice whose snapshot lost its paid timestamp — integrity
        // drift only a bug could cause (factory state: the domain seam
        // structurally cannot produce corrupt rows)
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $invoice = InvoiceReadModel::factory()->forCompany($company)->corruptMissingPaidTimestamp()->create();
        $this->bindMustNotRenderGuard();
        Exceptions::fake();

        // When they download the receipt
        $response = $this->downloadReceiptAs($owner, $company, $invoice->getKey());

        // Then the failure is the generic 500 envelope, validated BEFORE the
        // renderer is invoked, and reported with the invoice id for ops triage
        // (UC-1-121 error flow 7c; s135 F-e)
        $this->assertCorruptSnapshotRejected($response, $invoice->getKey());
    }

    // --- Edge ---

    #[Test]
    public function when_invoice_is_refunded_then_original_receipt_still_available(): void
    {
        // Given an owner whose invoice was paid and later refunded
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aRefundedInvoiceFor($company, $subscription);

        // When they download the receipt
        $response = $this->downloadReceiptAs($owner, $company, $invoice->getKey());

        // Then the ORIGINAL payment receipt still downloads — the refund produces
        // a separate credit-note artifact, never a receipt deletion (UC-1-121
        // scenario 5; SM-1-04 T6 keeps paid_at)
        $this->assertPdfReceiptResponse($response, $invoice->invoice_number);
    }

    #[Test]
    public function when_receipt_downloaded_again_then_rerendered_on_demand(): void
    {
        // Given an owner whose paid invoice's receipt was downloaded before
        Storage::fake();
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);
        $this->downloadReceiptAs($owner, $company, $invoice->getKey());

        // When they download it again
        $response = $this->downloadReceiptAs($owner, $company, $invoice->getKey());

        // Then the PDF is re-rendered on demand — no cached artifact read or
        // written anywhere, no download counter kept (UC-1-121 alt 7a; R3)
        $this->assertPdfReceiptResponse($response, $invoice->invoice_number);
        $this->assertNoArtifactPersisted();
    }

    // --- Happy ---

    #[Test]
    #[DataProvider('provideViewingRoles')]
    public function when_viewing_role_downloads_receipt_then_pdf_returned(string $roleCode): void
    {
        // Given a member whose role carries billing.view (owner / admin / auditor
        // per the 2026-05-24 matrix; s129 F3 role surface) and a paid invoice
        [$company, $actor] = $this->aCompanyWithMember($roleCode);
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);

        // When they download the receipt
        $response = $this->downloadReceiptAs($actor, $company, $invoice->getKey());

        // Then the PDF downloads and the read leaves no domain trace — the
        // paid stream still holds exactly its issue + payment events
        // (UC-1-121 minimal guarantee: no Reactor fires, no state mutates)
        $this->assertPdfReceiptResponse($response, $invoice->invoice_number);
        $this->assertNoNewInvoiceEvents($invoice->getKey(), 2);
    }

    #[Test]
    public function when_owner_downloads_receipt_for_paid_invoice_then_pdf_returned(): void
    {
        // Given an owner whose company holds the default paid invoice
        Storage::fake();
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);

        // When they download the receipt
        $response = $this->downloadReceiptAs($owner, $company, $invoice->getKey());

        // Then a complete PDF downloads under the literal contract filename —
        // receipt-{invoice_number}.pdf — and nothing is persisted anywhere
        // (UC-1-121 main flow step 7; R3 regenerate-on-demand)
        $this->assertPdfReceiptResponse($response, 'INV-2026-000001');
        $this->assertNoArtifactPersisted();
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
