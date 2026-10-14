<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\Entities\DsarRequestRecord;

class ReviewDsarRequestQueueTest extends DsarTestCase
{
    protected Company $company;

    protected Company $otherCompany;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->otherCompany = Company::factory()->create();
        $this->admin = $this->aPlatformAdmin();
    }

    #[Test]
    public function when_no_token_is_presented_then_the_queue_request_is_rejected_as_unauthenticated(): void
    {
        // Given a request in a company
        DsarRequestRecord::factory()->forSubject($this->company, $this->aCompanyMember($this->company))->create();

        // When a guest lists the queue
        $response = $this->listQueue(null);

        // Then the guest is refused and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoQueueReadRecorded();
    }

    #[Test]
    #[DataProvider('provideTenantRolesDeniedTheAdminSurface')]
    public function when_a_tenant_member_requests_the_dsar_queue_then_the_admin_surface_is_not_acknowledged(string $roleCode): void
    {
        // Given a member of a company in this role, not a platform admin, and a request in their own company
        $tenantMember = $this->aCompanyMember($this->company, $roleCode);
        DsarRequestRecord::factory()->forSubject($this->company, $tenantMember)->create();

        // When they list the queue
        $response = $this->listQueue($tenantMember);

        // Then the admin surface is not acknowledged — not even their own company's request — and no read is recorded
        $this->assertResourceNotFound($response);
        $response->assertJsonMissingPath('data');
        $this->assertNoQueueReadRecorded();
    }

    /**
     * List the DSAR queue on the platform-admin surface as $caller, or as a
     * guest when null — no company header, the admin family is cross-tenant.
     *
     * @param  array<string, mixed>  $query
     */
    protected function listQueue(?User $caller, array $query = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->getJson('/api/v1/admin/dsar-requests?'.http_build_query($query));
    }

    /**
     * No read of the queue was recorded.
     */
    protected function assertNoQueueReadRecorded(): void
    {
        $this->assertDatabaseMissing('audit_trail', ['action' => 'dsar_queue_viewed']);
    }
}
