<?php

namespace Tests\Feature\PlatformAdmin;

use PHPUnit\Framework\Attributes\Test;

/**
 * UC-1-127 — Review Ownership Transfer Queue (slice #11 Task 3): the platform
 * admin's cross-tenant triage queue at GET /api/v1/admin/ownership-transfers
 * (auth:sanctum + platform.admin, deliberately NO auth.company), ordered
 * soonest-deadline-first (F1), defaulting to the open states (F5), and
 * recording ONE platform-level audit_trail row per read (F3).
 */
class ReviewOwnershipTransferQueueTest extends OwnershipTransferAdminTestCase
{
    // --- Sad ---

    #[Test]
    public function when_no_token_is_presented_then_the_request_is_rejected_as_unauthenticated(): void
    {
        // Given nobody is authenticated

        // When the admin queue is requested as a guest
        $response = $this->getAdminQueue(null);

        // Then the request is rejected as unauthenticated and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }
}
