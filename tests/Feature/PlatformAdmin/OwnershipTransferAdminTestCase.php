<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
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
