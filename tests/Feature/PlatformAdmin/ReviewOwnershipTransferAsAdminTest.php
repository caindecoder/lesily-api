<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
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
}
