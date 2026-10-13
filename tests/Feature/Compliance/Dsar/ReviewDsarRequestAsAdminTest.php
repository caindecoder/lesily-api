<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

class ReviewDsarRequestAsAdminTest extends DsarTestCase
{
    protected Company $company;

    protected User $member;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->member = $this->aCompanyMember($this->company);
        $this->admin = User::factory()->create();
        config(['platform.admin_user_ids' => [$this->admin->getKey()]]);
    }

    #[Test]
    public function when_no_token_is_presented_then_the_detail_request_is_rejected_as_unauthenticated(): void
    {
        // Given a submitted request
        $request = $this->aSubmittedRequest($this->company, $this->member);

        // When a guest asks for it on the admin surface
        $response = $this->viewAsAdmin(null, $request->getKey());

        // Then the guest is refused and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoAdminReadRecorded();
    }

    #[Test]
    #[DataProvider('provideTenantRolesDeniedTheAdminSurface')]
    public function when_a_tenant_member_requests_a_dsar_detail_then_the_admin_surface_is_not_acknowledged(string $roleCode): void
    {
        // Given a member of the company in this role, not a platform admin, and a request in that company
        $tenantMember = $this->aCompanyMember($this->company, $roleCode);
        $request = $this->aSubmittedRequest($this->company, $this->member);

        // When they ask for it on the admin surface
        $response = $this->viewAsAdmin($tenantMember, $request->getKey());

        // Then the admin surface is not acknowledged and no read is recorded
        $this->assertHiddenAsNotFound($response, $request->getKey());
        $this->assertNoAdminReadRecorded();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTenantRolesDeniedTheAdminSurface(): array
    {
        return [
            'owner' => ['owner'],
            'auditor' => ['auditor'],
        ];
    }

    /**
     * Ask for one DSAR request on the platform-admin surface as $caller, or as
     * a guest when null — no company header, the admin family is cross-tenant.
     */
    protected function viewAsAdmin(?User $caller, string $requestId): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->getJson("/api/v1/admin/dsar-requests/{$requestId}");
    }

    /**
     * No platform-admin read was recorded — the fixtures' own outcome rows
     * (fulfilled, rejected) are not reads.
     */
    protected function assertNoAdminReadRecorded(): void
    {
        $this->assertDatabaseMissing('audit_trail', ['action' => 'dsar_admin_viewed']);
    }
}
