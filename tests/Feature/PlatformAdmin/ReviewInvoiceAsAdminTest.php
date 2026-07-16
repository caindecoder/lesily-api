<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice-plans-billing T7 — UC-1-122 Review Invoice (Admin): the cross-tenant
 * platform-admin invoice read at GET /api/v1/admin/invoices/{invoiceId}
 * (auth:sanctum + platform.admin — B2 INTERIM allowlist; deliberately NO
 * auth.company), returning InvoiceAdminResource and recording ONE append-only
 * audit_trail row on 200, none on 404 (cd-invoices §5 + cd-audit-trail
 * Direct-write arm; audit write lives in the interactor — s129 R8/E07).
 */
class ReviewInvoiceAsAdminTest extends PlatformAdminTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();

        // Error-path tests must exercise the production render path (the
        // bootstrap catch-all behaves differently under debug — amendment M).
        config(['app.debug' => false]);
    }

    // Sad — the admin endpoint never acknowledges its existence to tenant-side
    // callers: every seeded tenant role probing EVEN ITS OWN company's invoice
    // via the /admin/ path gets the byte-identical generic 404, and the read
    // is never recorded (UC-1-122 2a + Gherkin sc.4/sc.5).
    #[Test]
    #[DataProvider('provideTenantRoles')]
    public function when_a_tenant_role_calls_the_admin_invoice_endpoint_then_an_indistinguishable_not_found_is_returned(string $roleCode): void
    {
        // Given a tenant member whose own company holds a paid invoice, while the allowlist names only a different user
        [$company, $member] = $this->aCompanyWithMember($roleCode);
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);
        $this->allowlist([(string) Str::uuid()]);

        // When the member calls the admin invoice endpoint for that invoice
        $response = $this->reviewInvoiceAsAdmin($member, $invoice->getKey());

        // Then the response is the indistinguishable generic not-found and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    // Sad — no Sanctum token: rejected upstream by auth:sanctum as 401
    // UNAUTHENTICATED; UC-1-122's main flow is never entered and no read is
    // recorded (Gherkin sc.6, error-flow 2b).
    #[Test]
    public function when_no_token_is_presented_then_the_request_is_rejected_as_unauthenticated(): void
    {
        // Given a paid invoice exists and nobody is authenticated
        [$company] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);

        // When the admin invoice endpoint is called as a guest
        $response = $this->reviewInvoiceAsAdmin(null, $invoice->getKey());

        // Then the request is rejected as unauthenticated and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    // Sad — an allowlisted admin asks for an id that exists nowhere: the
    // generic 404, and NO audit row — the read did not happen (Gherkin sc.7,
    // error-flow 3a; the insert-throws-AFTER-find ordering gets its revert-
    // check at the audit lap).
    #[Test]
    public function when_the_invoice_id_is_unknown_then_not_found_is_returned_and_no_read_is_recorded(): void
    {
        // Given an allowlisted platform admin and no invoice anywhere
        $admin = User::factory()->create();
        $this->allowlist([$admin->getKey()]);

        // When the admin asks for a random unknown invoice id
        $response = $this->reviewInvoiceAsAdmin($admin, (string) Str::uuid());

        // Then the response is the generic not-found and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    // Edge — cross-tenant is the point: an allowlisted admin who is ALSO a
    // member of some tenant reads ANOTHER company's invoice; no auth.company
    // in the chain, no company scope on the lookup (UC-1-122 Tenant Isolation
    // — "intentionally cross-tenant").
    #[Test]
    public function when_the_admin_also_belongs_to_a_tenant_then_another_companys_invoice_is_still_readable(): void
    {
        // Given an allowlisted admin who is an Owner of company X, and a paid invoice belonging to company Y
        [, $admin] = $this->aCompanyWithMember('owner');
        $this->allowlist([$admin->getKey()]);
        [$companyY] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($companyY, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($companyY, $subscription);

        // When the admin reads company Y's invoice via the admin endpoint
        $response = $this->reviewInvoiceAsAdmin($admin, $invoice->getKey());

        // Then the cross-tenant invoice is returned
        $response->assertOk();
        $response->assertJsonPath('data.id', $invoice->getKey());
    }

    // Edge — the admin read is status-agnostic (UC-1-122 precondition: "any
    // company, any status"): non-paid invoices the tenant receipt endpoint
    // would 409 stay fully readable on the admin surface.
    #[Test]
    #[DataProvider('provideNonPaidStates')]
    public function when_the_invoice_is_not_paid_then_the_admin_can_still_review_it(string $state, string $expectedStatus): void
    {
        // Given an allowlisted admin and a tenant invoice in a non-paid state
        $admin = User::factory()->create();
        $this->allowlist([$admin->getKey()]);
        [$company] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anInvoiceInState($state, $company, $subscription);

        // When the admin reviews that invoice
        $response = $this->reviewInvoiceAsAdmin($admin, $invoice->getKey());

        // Then the invoice is returned with its stored status and that state's fields on the wire
        $response->assertOk();
        $response->assertJsonPath('data.status', $expectedStatus);
        $this->assertStateFieldsRendered($response, $state);
    }

    /**
     * The state-conditional admin-view fields actually carry the state's
     * values on the wire (agent-triage TA-1 — executed-but-unasserted
     * renderings are Infection-invisible: property reads mint no mutants).
     */
    private function assertStateFieldsRendered(TestResponse $response, string $state): void
    {
        match ($state) {
            'voided' => [
                $response->assertJsonPath('data.void_reason', 'Issued in error - admin correction.'),
                $response->assertJsonPath('data.void_source', 'admin-correction'),
                $this->assertNotNull($response->json('data.voided_at')),
                $this->assertNotNull($response->json('data.voided_by')),
            ],
            'failed' => [
                $response->assertJsonPath('data.failure_count', 1),
                $response->assertJsonPath('data.last_failure_code', 'insufficient_funds'),
                $this->assertNotNull($response->json('data.failed_at')),
            ],
            'disputed' => $response->assertJsonPath('data.has_dispute', true),
            default => throw new InvalidArgumentException("Unknown state [{$state}]."),
        };
    }

    // Edge — a tenant with no plan renders company.plan as null: the resource's
    // null-safe is load-bearing (plan is NULL during trial per the s066 pin,
    // and null-plan rows are exactly the gone/compliance tenants this endpoint
    // exists to review — agent-triage TA-2).
    #[Test]
    public function when_the_tenant_has_no_plan_then_the_company_summary_renders_it_as_null(): void
    {
        // Given an allowlisted admin and a paid invoice belonging to a company with no plan
        $admin = User::factory()->create();
        $this->allowlist([$admin->getKey()]);
        $company = Company::factory()->withoutPlan()->create();
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);

        // When the admin reviews that invoice
        $response = $this->reviewInvoiceAsAdmin($admin, $invoice->getKey());

        // Then the company summary carries a null plan (not a 500, not a missing key)
        $response->assertOk();
        $response->assertJsonPath('data.company.plan', null);
    }

    /**
     * The three non-paid arrangements: two terminal-ish stored states plus the
     * open-dispute modifier on a still-pending invoice (stored status stays
     * `pending` — has_dispute is orthogonal, the s134 modifier-orthogonality pin).
     *
     * @return array<string, array{string, string}>
     */
    public static function provideNonPaidStates(): array
    {
        return [
            'voided' => ['voided', 'voided'],
            'failed' => ['failed', 'failed'],
            'disputed (pending + open dispute)' => ['disputed', 'pending'],
        ];
    }

    // Happy — the full admin contract, locked byte-for-byte (exact-envelope
    // leak-guard, spec §6): every designed key emitted; composites null until
    // their producing slices; tenant_soft_deleted constant false (F-a) and
    // legal_hold null (OQ-1-135) — the s137 F-c contract pin (Gherkin sc.1,
    // contract half).
    #[Test]
    public function when_a_platform_admin_reviews_a_tenant_invoice_then_the_full_admin_contract_is_returned(): void
    {
        // Given an allowlisted admin and a Pro tenant's paid invoice with two line items
        $admin = User::factory()->create();
        $this->allowlist([$admin->getKey()]);
        [$company] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);
        [$planLine, $creditLine] = $invoice->lineItems()->get()->all();
        $paidAt = $invoice->paid_at;
        $this->assertNotNull($paidAt);

        // When the admin reviews the invoice
        $response = $this->reviewInvoiceAsAdmin($admin, $invoice->getKey());

        // Then the full admin envelope is returned exactly
        $response->assertOk();
        $response->assertExactJson(['data' => [
            'id' => $invoice->getKey(),
            'number' => 'INV-2026-000001',
            'issued_at' => $invoice->issued_at->toJSON(),
            'due_at' => $invoice->due_date->toDateString(),
            'status' => 'paid',
            'amount_cents' => 60000,
            'currency' => 'LKR',
            'has_dispute' => false,
            'source' => 'subscribe',
            'paid_at' => $paidAt->toJSON(),
            'payhere_transaction_id' => 'txn_'.$invoice->getKey(),
            'failed_at' => null,
            'failure_count' => 0,
            'last_failure_code' => null,
            'next_retry_at' => null,
            'voided_at' => null,
            'voided_by' => null,
            'void_reason' => null,
            'void_source' => null,
            'refunded_at' => null,
            'refund_amount_cents' => null,
            'payhere_refund_transaction_id' => null,
            'refund_reason' => null,
            'refund_source' => null,
            'recipient_name' => 'ACME (Pvt) Ltd',
            'recipient_email' => 'billing@acme.example',
            'recipient_billing_address' => null,
            'line_items' => [
                [
                    'id' => $planLine->getKey(),
                    'description' => 'Pro Plan — Monthly',
                    'quantity' => '1.00',
                    'unit_price_cents' => 75000,
                    'amount_cents' => 75000,
                    'currency' => 'LKR',
                    'line_type' => 'plan_subscription',
                    'reference_id' => null,
                    'reference_type' => null,
                    'period_start' => null,
                    'period_end' => null,
                    'sort_order' => 0,
                ],
                [
                    'id' => $creditLine->getKey(),
                    'description' => 'Proration credit — unused Starter days',
                    'quantity' => '-1.00',
                    'unit_price_cents' => 15000,
                    'amount_cents' => -15000,
                    'currency' => 'LKR',
                    'line_type' => 'proration_credit',
                    'reference_id' => null,
                    'reference_type' => null,
                    'period_start' => null,
                    'period_end' => null,
                    'sort_order' => 1,
                ],
            ],
            'payment_attempts' => null,
            'dispute_timeline' => null,
            'audit_trail_summary' => null,
            'tenant_soft_deleted' => false,
            'legal_hold' => null,
            'company' => [
                'name' => $company->name,
                'br_number' => $company->br_number,
                'epf_number' => $company->epf_number,
                'plan' => 'pro',
                'status' => 'trial',
            ],
        ]]);
    }

    // Edge — oversized request-context values (a long AV/enterprise User-Agent,
    // a client-minted Correlation-Id) must not break a valid admin read: the
    // audit write bounds both to their column width (MySQL strict mode turns an
    // over-length insert into SQLSTATE 22001 — the s137 I-1 review finding;
    // only allowlisted admins ever reach this write, so bounded fidelity wins
    // over schema churn per Chamil's ruling).
    #[Test]
    public function when_the_request_context_values_are_oversized_then_the_read_still_succeeds(): void
    {
        // Given an allowlisted admin whose client sends oversized UA and correlation headers
        $admin = User::factory()->create();
        $this->allowlist([$admin->getKey()]);
        [$company] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);

        // When the admin reviews the invoice with 300-char header values
        $response = $this->reviewInvoiceAsAdmin($admin, $invoice->getKey(), [
            'User-Agent' => str_repeat('a', 300),
            'Correlation-Id' => str_repeat('b', 300),
        ]);

        // Then the read succeeds and the audit row stores bounded values
        $response->assertOk();
        $this->assertDatabaseCount('audit_trail', 1);
        $entry = AuditEntry::query()->sole();
        $this->assertLessThanOrEqual(255, strlen((string) $entry->user_agent));
        $this->assertLessThanOrEqual(255, strlen((string) $entry->request_id));
    }

    // Happy — the read is recorded: ONE append-only audit_trail row in the
    // cd-audit-trail Direct-write shape, written by the interactor AFTER the
    // find succeeds (Gherkin sc.1, audit half; B3 — plain insert, no domain
    // event on a read).
    #[Test]
    public function when_a_platform_admin_reviews_a_tenant_invoice_then_the_read_is_recorded_in_the_audit_trail(): void
    {
        // Given an allowlisted admin and a tenant's paid invoice
        $admin = User::factory()->create();
        $this->allowlist([$admin->getKey()]);
        [$company] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->aPaidInvoiceFor($company, $subscription);

        // When the admin reviews the invoice
        $response = $this->reviewInvoiceAsAdmin($admin, $invoice->getKey());

        // Then exactly one audit row records who read what, and when
        $response->assertOk();
        $this->assertAdminReviewAuditRecorded($invoice, $admin, $response);
    }

    /**
     * The six tenant-side roles of UC-1-122's Denied list (the gate's own
     * manager + unallowlisted-platform_super_admin pins live in
     * EnsurePlatformAdminTest — T6).
     *
     * @return array<string, array{string}>
     */
    public static function provideTenantRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'hr_officer' => ['hr_officer'],
            'payroll_officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
        ];
    }
}
