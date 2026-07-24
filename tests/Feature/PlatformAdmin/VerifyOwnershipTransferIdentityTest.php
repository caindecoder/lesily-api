<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

/**
 * SM-1-05 T2 — a platform admin confirms the requester's identity and moves the
 * transfer Requested → Verified (UC-1-113, slice #11 Task 4). One behavior, one
 * seam, one actor, so the illegal-transition refusals, the input rejections and
 * the successful walk all live here, ordered Sad → Edge → Happy (§2.14 Rule A).
 *
 * The legal-hold refusal is an INTERACTOR guard, not an aggregate one: legal_hold
 * is the Company aggregate's state and this aggregate never reads the database
 * (s149 ruling B1; LD ApproveLeaveAction holds its billing-cancelled guard the
 * same way, leaving only the state guard on the aggregate).
 */
class VerifyOwnershipTransferIdentityTest extends OwnershipTransferAdminTestCase
{
    #[Test]
    public function when_the_verification_is_unauthenticated_then_it_is_rejected(): void
    {
        // Given a transfer awaiting verification
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When a guest attempts to verify it
        $response = $this->verifyTransfer(null, $transfer->getKey());

        // Then the request is refused and the transfer is untouched
        $this->assertUnauthenticated($response);
        $this->assertTransferStillAwaitsVerification($transfer->getKey());
    }

    /**
     * The tenant roles the admin family must turn away with the SAME 404 an
     * unknown id produces. Born green — the platform.admin gate landed with the
     * route in lap 1 — so its teeth were proven by revert-check (drop the
     * middleware, watch all six rows RED, restore) rather than by a watched RED.
     */
    #[Test]
    #[DataProvider('provideTenantRoles')]
    public function when_the_caller_is_not_a_platform_admin_then_the_transfer_is_not_found(string $roleCode): void
    {
        // Given a transfer awaiting verification and a tenant member of its company
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);
        $caller = $this->aCompanyMember($company, $roleCode);

        // When that tenant role attempts to verify it
        $response = $this->verifyTransfer($caller, $transfer->getKey());

        // Then the surface denies it without acknowledging the transfer exists
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertTransferStillAwaitsVerification($transfer->getKey());
    }

    /**
     * Nothing was recorded and nothing moved: the stream carries no event at all
     * (the fixture is projected directly, so an empty stream IS the baseline),
     * no access was logged, and the row still sits in Requested. The trio is what
     * makes every refusal test prove a non-event rather than just a status code.
     */
    private function assertTransferStillAwaitsVerification(string $requestId): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()->count());
        $this->assertNoAuditRecorded();
        $this->assertSame(
            OwnershipTransferStatus::Requested,
            OwnershipTransferRequest::query()->findOrFail($requestId)->status,
        );
    }
}
