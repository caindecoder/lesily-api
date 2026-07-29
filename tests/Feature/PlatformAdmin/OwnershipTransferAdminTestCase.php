<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Notification;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use Tests\Feature\Companies\OwnershipTransfers\Testing\ArrangesOwnershipTransferStreams;

/**
 * Shared fixtures for the ownership-transfer PLATFORM-ADMIN reads (UC-1-127,
 * slice #11 Task 3) — the cross-tenant queue + detail behind auth:sanctum +
 * platform.admin. Sits under PlatformAdminTestCase so the admin-generic members
 * (allowlist, provideTenantRoles, the 401/404 envelope asserts) are inherited
 * while the ownership-transfer fixtures stay out of the invoice-heavy base
 * (§2.10 + E09 — one base per actor+subject pair).
 */
abstract class OwnershipTransferAdminTestCase extends PlatformAdminTestCase
{
    use ArrangesOwnershipTransferStreams;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The 404-denial DataProvider composes real seeded tenant roles.
        $this->seedRbacCatalog();
        // Error-path render parity (amendment M): APP_DEBUG=true masks the prod
        // render path, so rejections must render their mapped envelopes.
        config(['app.debug' => false]);
        // The admin surfaces were read-only until the verify transition (Task 4),
        // which is the first one here to fire a notification reactor.
        Notification::fake();
    }

    /**
     * The four terminal SM-1-05 states, shared by both admin read classes (the
     * same reasoning that lifted provideTenantRoles to PlatformAdminTestCase —
     * a matrix with a second consumer belongs on the base, not duplicated).
     *
     * @return array<string, array{OwnershipTransferStatus}>
     */
    public static function provideTerminalStates(): array
    {
        return [
            'executed' => [OwnershipTransferStatus::Executed],
            'rejected' => [OwnershipTransferStatus::Rejected],
            'cancelled' => [OwnershipTransferStatus::Cancelled],
            'expired' => [OwnershipTransferStatus::Expired],
        ];
    }

    /**
     * A company whose `companies.owner_id` user REALLY holds the owner pivot —
     * the arrangement every T4 row needs, and the one `aCompanyWithMember()`
     * cannot produce (ruling P6, s160).
     *
     * `aCompanyWithMember('owner')` mints a SECOND user and attaches the owner
     * role to them, leaving `companies.owner_id` pointing at the factory's user,
     * who has no `company_user` row at all: `CompanyFactory` attaches no pivot,
     * and the pivot's real writer — `CompanyProjector::onCompanyCreated` — never
     * fires on the factory path. For the read surfaces that never compared the
     * two that was harmless. T4 compares them in the interactor (P3) and mutates
     * BOTH pivots, so against that fixture the happy path can never reach 200,
     * the owner-changed guard passes vacuously, and the demotion assertion
     * matches zero rows — the s154 K1 shape.
     *
     * Mirrors `OwnershipTransfersTestCase::aCompanyWithOwner()` on the tenant
     * side; module bases are independent (established precedent). The attached
     * row is byte-identical to the one CompanyProjector writes in production.
     *
     * @return array{0: Company, 1: User}
     */
    protected function aCompanyWithOwner(): array
    {
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $owner = User::query()->findOrFail($company->owner_id);

        $company->users()->attach($owner, [
            'role_id' => $this->seededRole('owner')->getKey(),
        ]);

        return [$company, $owner];
    }

    /**
     * A fresh user who IS on the platform-admin allowlist — the only caller the
     * /api/v1/admin/* family lets through (F7: the interim config allowlist, not
     * a seeded permission).
     */
    protected function anAllowlistedAdmin(): User
    {
        $admin = User::factory()->create();
        $this->allowlist([$admin->getKey()]);

        return $admin;
    }

    /**
     * The request was refused as shape-invalid — the §S21 validation envelope
     * with an error on $field.
     */
    protected function assertValidationRejected(TestResponse $response, string $field): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors($field);
    }

    /**
     * The queue's `data` rows appear in exactly $idsInOrder (order-sensitive —
     * assertSame, deliberately not assertEqualsCanonicalizing, because ordering
     * IS the assertion here; LD draws the same distinction in
     * ListTrialCompaniesTest).
     *
     * @param  list<string>  $idsInOrder
     */
    protected function assertQueueOrder(TestResponse $response, array $idsInOrder): void
    {
        $this->assertSame($idsInOrder, $response->collect('data')->pluck('id')->all());
    }

    /**
     * Exactly ONE platform-level audit row records the queue read (F3): a
     * cross-tenant query has no single entity subject and spans companies, so
     * subject_id and company_id are both NULL — the cd-audit-trail
     * platformLevelOnly() category — with the query context in metadata.
     *
     * @param  array<string, mixed>  $expectedMetadata
     */
    protected function assertQueueReadAudited(TestResponse $response, User $admin, array $expectedMetadata): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'AdminOwnershipTransferQueueViewed',
            'subject_type' => 'ownership_transfer_queue',
            'subject_id' => null,
            'company_id' => null,
            'subject_label' => 'Ownership transfer queue',
            'action' => 'admin_queue_viewed',
            // The ACTOR is the whole point of this row: it is the only record of
            // who ran an unscoped query across every tenant's pipeline, which is
            // the transparency-of-access the UC exists to produce. actor_user_id
            // is nullable, so without these two a regression that drops the
            // actor keeps CI green and destroys exactly the evidence.
            'actor_user_id' => $admin->getKey(),
            'actor_label' => $admin->name,
            'actor_role_code' => 'platform_super_admin',
            'stored_event_id' => null,
            'severity' => 'info',
            'ip_address' => '127.0.0.1',
        ]);

        $entry = AuditEntry::query()->sole();
        // assertEquals, not assertSame: MySQL normalizes JSON object key order
        // (by key length, then bytewise) on storage, so a round-tripped metadata
        // array comes back reordered. The contract is the key/value content —
        // assertEquals still requires exactly these keys with exactly these
        // values, it just does not assert MySQL's internal ordering.
        $this->assertEquals($expectedMetadata, $entry->metadata);
        $this->assertSame($response->headers->get('Correlation-Id'), $entry->request_id);
    }

    /**
     * A persisted transfer row for $company targeting $target in $state (the
     * company's factory owner is the initiator). The endpoints that PRODUCE the
     * non-requested states land in later slice tasks; a read fixture arranges
     * the projection directly. Duplicated from OwnershipTransfersTestCase —
     * module bases are independent (established precedent).
     */
    protected function aTransferInState(Company $company, User $target, OwnershipTransferStatus $state): OwnershipTransferRequest
    {
        $factory = OwnershipTransferRequest::factory();
        $factory = match ($state) {
            OwnershipTransferStatus::Requested => $factory->requested(),
            OwnershipTransferStatus::Verified => $factory->verified(),
            OwnershipTransferStatus::Executed => $factory->executed(),
            OwnershipTransferStatus::Rejected => $factory->rejected(),
            OwnershipTransferStatus::Cancelled => $factory->cancelled(),
            OwnershipTransferStatus::Expired => $factory->expired(),
        };

        return $factory->create([
            'company_id' => $company->getKey(),
            'current_owner_user_id' => $company->owner_id,
            'target_user_id' => $target->getKey(),
        ]);
    }

    /**
     * GET a single transfer on the admin surface as $caller, or as a guest when
     * null. No X-Company-Context, same as the queue.
     */
    protected function getAdminTransfer(?User $caller, string $requestId): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->getJson("/api/v1/admin/ownership-transfers/{$requestId}");
    }

    /**
     * Exactly ONE audit row records the admin's detail read, in the UC-1-122
     * shape: this read DOES name an entity subject and DOES belong to a tenant,
     * so subject_id and company_id are both populated — the contrast with the
     * queue row that F3 turns on.
     */
    protected function assertTransferReadAudited(OwnershipTransferRequest $transfer, User $admin, TestResponse $response): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'AdminOwnershipTransferReviewed',
            'subject_type' => 'ownership_transfer_request',
            'subject_id' => $transfer->getKey(),
            'company_id' => $transfer->company_id,
            'subject_label' => 'Ownership transfer request',
            'description' => 'Platform admin reviewed an ownership transfer request.',
            'action' => 'admin_reviewed',
            'actor_user_id' => $admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'stored_event_id' => null,
            'severity' => 'info',
            'ip_address' => '127.0.0.1',
        ]);

        $entry = AuditEntry::query()->sole();
        $this->assertSame($response->headers->get('Correlation-Id'), $entry->request_id);
        // metadata is the free-form column, and it is the one place a
        // cross-tenant query context could end up inside a row stamped with a
        // single tenant's company_id — which that tenant can read via UC-1-066.
        $this->assertNull($entry->metadata);
    }

    /**
     * A transfer for $company in $state whose SLA timer falls at $deadline —
     * the fixture the deadline-first ordering assertion turns on. A named
     * helper carrying the column override is the sanctioned shape (§2.13 bans
     * inline overrides in test bodies, not fixtures; aTransferRequestedAt is
     * the Task-2 precedent).
     */
    protected function aTransferDueAt(
        Company $company,
        OwnershipTransferStatus $state,
        CarbonImmutable $deadline,
    ): OwnershipTransferRequest {
        $transfer = $this->aTransferInState($company, User::factory()->create(), $state);
        $transfer->forceFill(['next_state_transition_at' => $deadline])->save();

        return $transfer;
    }

    /**
     * PATCH the verify transition as $caller, or as a guest when null. Same
     * no-X-Company-Context shape as the admin reads; Tasks 5/8 sit their
     * reject/execute action helpers beside this one.
     *
     * @param  array<string, mixed>  $body
     */
    protected function verifyTransfer(?User $caller, string $requestId, array $body = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->patchJson("/api/v1/admin/ownership-transfers/{$requestId}/verify", $body);
    }

    /**
     * A transfer belonging to an entirely different tenant, left alone. Its only
     * job is to be a second row, which is what makes a projector's or a guard's
     * `whereKey(...)` scope observable at all — with one row in the fixture an
     * unscoped read returns the right one by insertion order (s149 C6).
     */
    protected function aBystanderTransferAtAnotherCompany(): OwnershipTransferRequest
    {
        [$otherCompany, $otherOwner] = $this->aCompanyWithOwner();

        return $this->aRequestedTransferStream($otherCompany, $otherOwner, $this->aCompanyMember($otherCompany));
    }

    /**
     * The transition was refused by a state guard — the shared 409 family the
     * §8.4 registry reserves for illegal state-machine moves.
     *
     * The MESSAGE is asserted, not just the code, and that is the point: several
     * refusals render the identical envelope, so without a discriminator throwing
     * the wrong exception would stay green and the unused render arm in
     * bootstrap/app.php would become dead code in the one file Infection cannot
     * see and coverage does not track. This survives substitution, not merely
     * deletion.
     */
    protected function assertStateGuardRefused(TestResponse $response, string $expectedMessage): void
    {
        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'STATE_GUARD_VIOLATION');
        $response->assertJsonPath('message', $expectedMessage);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * PATCH the SM-1-05 T4 execute endpoint as $caller, or as a guest when null.
     * Same shape as verifyTransfer/rejectTransfer — the admin family carries no
     * auth.company. Unlike its two siblings this route DOES mount
     * `idempotency-key:required` (api-conventions §13.2 names
     * ownership-transfer-execute as one of the four required endpoints), so a
     * fresh key rides every call — the `postTransferRequest` idiom on the tenant
     * base, which mints one per call for the same reason.
     *
     * @param  array<string, mixed>  $body
     */
    protected function executeTransfer(?User $caller, string $requestId, array $body = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->patchJson("/api/v1/admin/ownership-transfers/{$requestId}/execute", $body, [
            'Idempotency-Key' => fake()->uuid(),
        ]);
    }

    /**
     * PATCH execute with NO Idempotency-Key — the required-mount sad path,
     * mirroring `postTransferRequestWithoutKey` on the tenant base.
     *
     * @param  array<string, mixed>  $body
     */
    protected function executeTransferWithoutKey(User $caller, string $requestId, array $body = []): TestResponse
    {
        Sanctum::actingAs($caller);

        return $this->patchJson("/api/v1/admin/ownership-transfers/{$requestId}/execute", $body);
    }

    /**
     * PATCH execute twice under the SAME Idempotency-Key — the replayed click
     * the required mount exists to absorb.
     *
     * The key is passed in rather than minted per call, which is the whole
     * point: `executeTransfer` mints a fresh `fake()->uuid()` every time, so no
     * test using it can ever exercise a replay. Mirrors
     * `postTransferRequestWithSameKey` on the tenant base (UC-1-028's precedent
     * for the same mount).
     *
     * @param  array<string, mixed>  $body
     * @return array{0: TestResponse, 1: TestResponse}
     */
    protected function executeTransferTwiceWithSameKey(User $caller, string $requestId, array $body = []): array
    {
        Sanctum::actingAs($caller);

        $key = fake()->uuid();
        $send = fn (): TestResponse => $this->patchJson(
            "/api/v1/admin/ownership-transfers/{$requestId}/execute",
            $body,
            ['Idempotency-Key' => $key],
        );

        return [$send(), $send()];
    }

    /**
     * PATCH the reject transition as $caller, or as a guest when null. Same
     * no-X-Company-Context shape as its verify sibling; Task 8 sits
     * executeTransfer() beside the pair.
     *
     * @param  array<string, mixed>  $body
     */
    protected function rejectTransfer(?User $caller, string $requestId, array $body = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->patchJson("/api/v1/admin/ownership-transfers/{$requestId}/reject", $body);
    }

    /**
     * GET the cross-tenant admin queue as $caller, or as a guest when null.
     * Deliberately NO X-Company-Context — the /api/v1/admin/* family carries no
     * auth.company (cd-foundation-http §3).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    protected function getAdminQueue(?User $caller, array $query = [], array $headers = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        $url = '/api/v1/admin/ownership-transfers'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->getJson($url, $headers);
    }
}
