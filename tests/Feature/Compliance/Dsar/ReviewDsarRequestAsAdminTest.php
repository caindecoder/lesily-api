<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

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

    #[Test]
    #[DataProvider('provideUnknownRequestIds')]
    public function when_the_request_id_is_unknown_then_nothing_is_found_and_no_read_is_recorded(string $unknownId): void
    {
        // Given a platform admin and a request that is not the one asked for
        $this->aSubmittedRequest($this->company, $this->member);

        // When the admin asks for an id no request has
        $response = $this->viewAsAdmin($this->admin, $unknownId);

        // Then nothing is found and no read is recorded
        $this->assertHiddenAsNotFound($response, $unknownId);
        $this->assertNoAdminReadRecorded();
    }

    #[Test]
    public function when_the_admin_opens_a_fulfilled_request_then_its_timeline_shows_the_download_expiry(): void
    {
        // Given a request made on 1 Oct, fulfilled on 8 Oct, its download expired on 15 Oct
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->travelTo('2026-10-08 09:00:00');
        $this->runTheExportFor($request);
        $this->expireTheDownloadAt($request, '2026-10-15 09:00:00');

        // When the admin opens it on 15 Oct
        $response = $this->viewAsAdmin($this->admin, $request->getKey());

        // Then the whole admin view is shown, the read is recorded and the request is unchanged
        $response->assertExactJson(['data' => [
            'id' => $request->getKey(),
            'status' => 'fulfilled',
            'request_type' => 'access',
            'subject' => ['id' => $this->member->getKey(), 'name' => $this->member->name],
            'requested_by' => ['id' => $this->member->getKey(), 'name' => $this->member->name],
            'requested_at' => '2026-10-01T09:00:00.000000Z',
            'sla_deadline' => '2026-10-31T09:00:00.000000Z',
            'completed_at' => '2026-10-08T09:00:00.000000Z',
            'download_available' => false,
            'download_expires_at' => '2026-10-15T09:00:00.000000Z',
            'cancelled_at' => null,
            'rejection_reason' => null,
            'company' => ['id' => $this->company->getKey(), 'name' => $this->company->name],
            'days_remaining' => 16,
            'sla_breached_at' => null,
            'download_expired_at' => '2026-10-15T09:00:00.000000Z',
            'timeline' => [
                ['event' => 'requested', 'at' => '2026-10-01T09:00:00.000000Z'],
                ['event' => 'fulfilled', 'at' => '2026-10-08T09:00:00.000000Z'],
                ['event' => 'download_expired', 'at' => '2026-10-15T09:00:00.000000Z'],
            ],
        ]]);
        $this->assertAdminReadAudited($request, $response);
        $this->assertStreamUnchanged($request, 3);
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
     * $request's download expires at $at, recorded by the aggregate itself.
     */
    protected function expireTheDownloadAt(DsarRequestRecord $request, string $at): void
    {
        $this->travelTo($at);
        DsarRequest::retrieve($request->getKey())->expireDownload(CarbonImmutable::now())->persist();
    }

    /**
     * Exactly one platform-admin read of $request is recorded — against the
     * request and its company, by the admin, with the request's context.
     */
    protected function assertAdminReadAudited(DsarRequestRecord $request, TestResponse $response): void
    {
        $this->assertSame(1, AuditEntry::query()->where('action', 'dsar_admin_viewed')->count());
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => $request->company_id,
            'stored_event_id' => null,
            'event_class' => 'DsarRequestViewedByAdmin',
            'subject_type' => 'dsar_request',
            'subject_id' => $request->getKey(),
            'subject_label' => null,
            'action' => 'dsar_admin_viewed',
            'description' => 'DSAR request viewed by a platform admin.',
            'actor_user_id' => $this->admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->admin->name,
            'ip_address' => '127.0.0.1',
            'metadata' => null,
        ]);

        $entry = AuditEntry::query()->where('action', 'dsar_admin_viewed')->sole();
        $this->assertSame($response->headers->get('Correlation-Id'), $entry->request_id);
        $this->assertNotNull($entry->user_agent);
    }

    /**
     * Viewing recorded nothing on $request's stream — it still holds $events.
     */
    protected function assertStreamUnchanged(DsarRequestRecord $request, int $events): void
    {
        $this->assertSame($events, LesilyStoredEvent::query()->where('aggregate_uuid', $request->getKey())->count());
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
