<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
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
