<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use RuntimeException;
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
        $this->admin = $this->aPlatformAdmin();
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
    public function when_the_audit_write_fails_then_the_detail_returns_no_request_data(): void
    {
        // Given a submitted request and an audit write that fails after its row is inserted
        $request = $this->aSubmittedRequest($this->company, $this->member);
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin opens it
        $response = $this->viewAsAdmin($this->admin, $request->getKey());

        // Then the read fails closed and the inserted row is rolled back with it
        $this->assertFailedClosed($response);
        $this->assertNoAdminReadRecorded();
    }

    #[Test]
    #[DataProvider('provideDaysAroundTheDeadline')]
    public function when_the_deadline_is_near_then_days_remaining_counts_calendar_days(string $viewedAt, int $daysRemaining): void
    {
        // Given a request whose deadline is 31 Oct 09:00 UTC, viewed at this row's moment
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->travelTo($viewedAt);

        // When the admin opens it
        $response = $this->viewAsAdmin($this->admin, $request->getKey());

        // Then the days remaining count calendar days on UTC dates
        $response->assertOk();
        $response->assertJsonPath('data.days_remaining', $daysRemaining);
    }

    #[Test]
    public function when_a_breached_request_is_fulfilled_late_then_its_timeline_shows_the_breach_first(): void
    {
        // Given a request flagged at its 31 Oct deadline and fulfilled on 2 Nov
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->flagTheBreachAt($request, '2026-10-31 09:00:00');
        $this->travelTo('2026-11-02 09:00:00');
        $this->runTheExportFor($request);

        // When the admin opens it on 3 Nov, while its download window is open
        $this->travelTo('2026-11-03 09:00:00');
        $response = $this->viewAsAdmin($this->admin, $request->getKey());

        // Then the breach precedes the fulfilment on its timeline and the download shows as available
        $this->assertShown($response, [
            'status' => 'fulfilled',
            'download_available' => true,
        ], [
            ['event' => 'requested', 'at' => '2026-10-01T09:00:00.000000Z'],
            ['event' => 'sla_breached', 'at' => '2026-10-31T09:00:00.000000Z'],
            ['event' => 'fulfilled', 'at' => '2026-11-02T09:00:00.000000Z'],
        ]);
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
        $response->assertOk();
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

    #[Test]
    public function when_the_admin_opens_a_breached_request_then_it_shows_negative_days_and_the_breach(): void
    {
        // Given a request the Owner made for a member on 1 Oct, flagged by the daily sweep at its 31 Oct deadline, still in progress
        $owner = $this->aCompanyMember($this->company, 'owner');
        $request = $this->aSubmittedRequest($this->company, $this->member, $owner);
        $this->flagTheBreachAt($request, '2026-10-31 09:00:00');
        $this->travelTo('2026-11-15 09:00:00');

        // When the admin opens it on 15 Nov
        $response = $this->viewAsAdmin($this->admin, $request->getKey());

        // Then it shows who it is about and who asked, fifteen days past the deadline, and the breach on its timeline
        $this->assertShown($response, [
            'subject.id' => $this->member->getKey(),
            'requested_by.id' => $owner->getKey(),
            'status' => 'in_progress',
            'days_remaining' => -15,
            'sla_breached_at' => '2026-10-31T09:00:00.000000Z',
        ], [
            ['event' => 'requested', 'at' => '2026-10-01T09:00:00.000000Z'],
            ['event' => 'sla_breached', 'at' => '2026-10-31T09:00:00.000000Z'],
        ]);
        $this->assertAdminReadAudited($request, $response);
    }

    #[Test]
    public function when_the_admin_opens_a_rejected_request_then_it_shows_the_rejection_reason(): void
    {
        // Given a request rejected after its export failed
        $request = $this->aClosedRequest($this->company, $this->member, 'rejected');

        // When the admin opens it
        $response = $this->viewAsAdmin($this->admin, $request->getKey());

        // Then it shows the rejection and its reason
        $this->assertShown($response, [
            'status' => 'rejected',
            'rejection_reason' => 'The export could not be built. Please submit a new request.',
        ], [
            ['event' => 'requested', 'at' => '2026-10-01T09:00:00.000000Z'],
            ['event' => 'rejected', 'at' => '2026-10-01T09:00:00.000000Z'],
        ]);
        $this->assertAdminReadAudited($request, $response);
    }

    #[Test]
    public function when_the_admin_opens_a_withdrawn_request_then_it_shows_the_cancellation(): void
    {
        // Given a request its requester withdrew before it was fulfilled
        $request = $this->aClosedRequest($this->company, $this->member, 'withdrawn');

        // When the admin opens it
        $response = $this->viewAsAdmin($this->admin, $request->getKey());

        // Then it shows the withdrawal
        $this->assertShown($response, [
            'status' => 'cancelled',
            'cancelled_at' => '2026-10-01T09:00:00.000000Z',
        ], [
            ['event' => 'requested', 'at' => '2026-10-01T09:00:00.000000Z'],
            ['event' => 'cancelled', 'at' => '2026-10-01T09:00:00.000000Z'],
        ]);
        $this->assertAdminReadAudited($request, $response);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function provideDaysAroundTheDeadline(): array
    {
        return [
            'the evening before' => ['2026-10-30 23:59:59', 1],
            'the deadline day begins' => ['2026-10-31 00:00:00', 0],
            'the deadline day ends' => ['2026-10-31 23:59:59', 0],
            'the day after' => ['2026-11-01 00:00:00', -1],
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
     * $request's missed deadline is flagged at $at, recorded by the aggregate itself.
     */
    protected function flagTheBreachAt(DsarRequestRecord $request, string $at): void
    {
        $this->travelTo($at);
        DsarRequest::retrieve($request->getKey())->flagSlaBreach(CarbonImmutable::now())->persist();
    }

    /**
     * The admin view shows each of $fields, and exactly $timeline as its timeline.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<array{event: string, at: string}>  $timeline
     */
    protected function assertShown(TestResponse $response, array $fields, array $timeline): void
    {
        $response->assertOk();
        $this->assertNotEmpty($fields);

        foreach ($fields as $key => $value) {
            $response->assertJsonPath("data.{$key}", $value);
        }

        $this->assertSame($timeline, $response->json('data.timeline'));
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
