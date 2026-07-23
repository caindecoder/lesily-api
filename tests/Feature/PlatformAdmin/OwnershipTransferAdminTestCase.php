<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

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
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The 404-denial DataProvider composes real seeded tenant roles.
        $this->seedRbacCatalog();
        // Error-path render parity (amendment M): APP_DEBUG=true masks the prod
        // render path, so rejections must render their mapped envelopes.
        config(['app.debug' => false]);
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
    protected function assertQueueReadAudited(TestResponse $response, array $expectedMetadata): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'AdminOwnershipTransferQueueViewed',
            'subject_type' => 'ownership_transfer_queue',
            'subject_id' => null,
            'company_id' => null,
            'action' => 'admin_queue_viewed',
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
     * GET the cross-tenant admin queue as $caller, or as a guest when null.
     * Deliberately NO X-Company-Context — the /api/v1/admin/* family carries no
     * auth.company (cd-foundation-http §3).
     *
     * @param  array<string, mixed>  $query
     */
    protected function getAdminQueue(?User $caller, array $query = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        $url = '/api/v1/admin/ownership-transfers'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->getJson($url);
    }
}
