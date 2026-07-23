<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;

/**
 * UC-1-127 — the platform admin's single-transfer detail read at
 * GET /api/v1/admin/ownership-transfers/{requestId}: cross-tenant, unscoped by
 * company, returning the forensic admin resource and recording ONE audit_trail
 * row on 200, none on 404.
 *
 * Split from ReviewOwnershipTransferQueueTest by seam (single-record lookup vs
 * paginated collection) — the same split Task 2 made for the tenant reads.
 */
class ReviewOwnershipTransferAsAdminTest extends OwnershipTransferAdminTestCase
{
    // --- Sad ---

    #[Test]
    public function when_no_token_is_presented_then_the_request_is_rejected_as_unauthenticated(): void
    {
        // Given a transfer exists and nobody is authenticated
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When the detail endpoint is called as a guest
        $response = $this->getAdminTransfer(null, $transfer->getKey());

        // Then the request is rejected as unauthenticated and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    // Sad — a tenant role reading its OWN company's transfer through the /admin/
    // path still gets the indistinguishable 404 (UC-1-127 error-flow 2a).
    #[Test]
    #[DataProvider('provideTenantRoles')]
    public function when_a_tenant_role_calls_the_admin_detail_endpoint_then_an_indistinguishable_not_found_is_returned(string $roleCode): void
    {
        // Given a tenant member whose own company holds the transfer, while the allowlist names a different user
        [$company, $member] = $this->aCompanyWithMember($roleCode);
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);
        $this->allowlist([(string) Str::uuid()]);

        // When that member reads it via the admin path
        $response = $this->getAdminTransfer($member, $transfer->getKey());

        // Then the response is the indistinguishable generic not-found and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    // Sad — an allowlisted admin asks for an id that exists nowhere: the generic
    // 404, and NO audit row, because the find throws BEFORE the insert.
    #[Test]
    public function when_the_transfer_id_is_unknown_then_not_found_is_returned_and_no_read_is_recorded(): void
    {
        // Given an allowlisted platform admin and a transfer that DOES exist —
        // present deliberately, so this pins "the lookup honours the id" and not
        // merely "an empty table returns nothing" (the weaker claim an empty
        // fixture would have made; caught by revert-check)
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When they ask for a DIFFERENT, unknown id
        $response = $this->getAdminTransfer($admin, (string) Str::uuid());

        // Then the response is the generic not-found and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    // --- Edge ---

    // Edge — cross-tenant is the point: an admin who is also a tenant member
    // reads a DIFFERENT company's transfer, with no company scope on the lookup.
    #[Test]
    public function when_the_admin_also_belongs_to_a_tenant_then_another_companys_transfer_is_still_readable(): void
    {
        // Given an allowlisted admin who owns company X, and a transfer belonging to company Y
        [, $admin] = $this->aCompanyWithMember('owner');
        $this->allowlist([$admin->getKey()]);
        [$companyY] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($companyY, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When the admin reads company Y's transfer
        $response = $this->getAdminTransfer($admin, $transfer->getKey());

        // Then it is returned
        $response->assertOk();
        $response->assertJsonPath('data.id', $transfer->getKey());
    }

    // Edge — the detail read is state-agnostic: terminal transfers stay fully
    // readable for forensic lookback long after the lifecycle ended.
    #[Test]
    #[DataProvider('provideTerminalStates')]
    public function when_the_transfer_is_in_a_terminal_state_then_it_is_still_readable(OwnershipTransferStatus $state): void
    {
        // Given an allowlisted admin and a transfer in a terminal state
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), $state);

        // When the admin reads it
        $response = $this->getAdminTransfer($admin, $transfer->getKey());

        // Then it is returned with its stored terminal state
        $response->assertOk();
        $response->assertJsonPath('data.status', $state->value);
    }

    /**
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
}
