<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
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
     * Nothing was recorded and nothing moved: the stream carries no event at all
     * (the fixture is projected directly, so an empty stream IS the baseline) and
     * the row still sits in Requested. The pair is what makes every refusal test
     * prove a non-event rather than just a status code.
     */
    private function assertTransferStillAwaitsVerification(string $requestId): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()->count());
        $this->assertSame(
            OwnershipTransferStatus::Requested,
            OwnershipTransferRequest::query()->findOrFail($requestId)->status,
        );
    }
}
